<?php

namespace App\Service;

use Alhames\DbBundle\Db\Db;
use Alhames\DbBundle\Db\DbManagerAwareInterface;
use Alhames\DbBundle\Db\DbManagerAwareTrait;
use Alhames\DbBundle\Db\DbQuery;
use App\Entity\PostEntity;
use App\Entity\UserEntity;
use App\Repository\PostRepository;
use App\Utils\Str;
use Pg\EntityBundle\Entity\EntityManagerAwareInterface;
use Pg\EntityBundle\Entity\EntityManagerAwareTrait;
use Pg\GameBundle\Entity\GameFollowerEntity;
use Pg\GameBundle\Repository\GameFollowerRepository;
use PhpHelper\DateTime;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class PostListProvider implements DbManagerAwareInterface, EntityManagerAwareInterface
{
    use DbManagerAwareTrait;
    use EntityManagerAwareTrait;

    const DEFAULT_SORTING_PRIMARY = 'popularity';
    const DEFAULT_SORTING_SECONDARY = 'creation_date';
    const DEFAULT_SORTING_MAIN_PAGE = 'main_page';

    private string $popularityPeriod;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $this->popularityPeriod = $parameterBag->get('app.limit.post.popularity_time');
    }

    public function getPostCount(array $criteria, string $sortBy): int
    {
        $sortingPeriod = $this->getSortingParams($sortBy)['period'];
        if (null !== $sortingPeriod) {
            $criteria['created_at'] = Db::more(dt('-'.$sortingPeriod));
        }

        $query = $this->getBaseQuery($criteria);

        return (int) $query->select(['COUNT(DISTINCT self.id)' => 'count'])
            ->groupBy(null)
            ->getRow('count');
    }

    public function getList(
        array $criteria,
        ?int $count = null,
        ?int $offset = null,
        ?string $sortBy = self::DEFAULT_SORTING_SECONDARY
    ): array {
        $sortedPosts = $this->getSortedPosts($criteria, $sortBy, $count, $offset);

        return $this->prepareList($sortedPosts);
    }

    public function getFeed(UserEntity $user, int $page, int $postsPerPage): array
    {
        /** @var GameFollowerRepository $gameFollowerRepo */
        $gameFollowerRepo = $this->getRepository(GameFollowerEntity::TYPE);
        $gameIds = $gameFollowerRepo->getGameIdsByUserId($user->id);
        if (empty($gameIds)) {
            return [];
        }

        $query = $this->db('post')
            ->select('self.*')
            ->orderBy(['self.created_at' => 'DESC'])
            ->setPage($page, $postsPerPage);
        $criteria = [
            'self.game_id' => $gameIds,
            'self.status' => PostRepository::getPublicStatuses(),
        ];

        $this->em->load($user, 'settings');
        $filter = $user->settings->feedFilter ?? [];
        if (!empty($filter['post_types'])) {
            $criteria['self.type'] = array_diff(PostEntity::getPostTypes(), $filter['post_types']);
        }
        if (!empty($filter['post_category_ids'])) {
            $query->join('post_category_rel', 'c', [
                'c.post_id' => Db::field('self.id'),
                'c.post_category_id' => $filter['post_category_ids'],
            ], 'LEFT');
            $query->groupBy('self.id');
            $criteria['c.id'] = null;
        }

        $postData = $query->where($criteria)->getRows();
        $posts = [];
        foreach ($postData as $post) {
            $posts[] = $this->em->createFromData(PostEntity::TYPE, $post);
        }

        return $this->prepareList($posts);
    }

    private function getSortedPosts(array $criteria, string $sortBy, ?int $count, ?int $offset): array
    {
        $sortingParams = $this->getSortingParams($sortBy);
        $sortingType = $sortingParams['type'];
        $sortingPeriod = $sortingParams['period'];

        if (null !== $sortingPeriod) {
            $criteria['created_at'] = Db::more(dt('-'.$sortingPeriod));
        }

        $query = $this->getBaseQuery($criteria, $count, $offset);

        $sortMethod = sprintf('getPostListBy%s', Str::convertCase($sortingType, Str::CASE_CAMEL_UPPER));
        $postIds = $this->$sortMethod($query, $count, $offset);

        return $this->em->getMultiple(PostEntity::TYPE, $postIds);
    }

    private function getSortingParams(string $sortBy): array
    {
        if (preg_match('/^(?<type>rating|creation_date|popularity|main_page)(_for_(?<period>day|week|month|year))?$/', $sortBy, $sortingParams)) {
            $sortingType = $sortingParams['type'];
            $sortingPeriod = $sortingParams['period'] ?? null;
        } else {
            $sortingType = self::DEFAULT_SORTING_SECONDARY;
            $sortingPeriod = null;
        }

        if (in_array($sortingType, ['popularity', 'main_page']) && null === $sortingPeriod) {
            $sortingPeriod = $this->popularityPeriod;
        } elseif (null !== $sortingPeriod) {
            $sortingPeriod = '1 '.$sortingPeriod;
        }

        return ['type' => $sortingType, 'period' => $sortingPeriod];
    }

    private function getBaseQuery(array $criteria, ?int $count = null, ?int $offset = null): DbQuery
    {
        $query = $this->db('post')->select(['self.id', 'self.sort_value']);

        if (isset($criteria['c.post_category_id'])) {
            $query->join('post_category_rel', 'c', ['c.post_id' => Db::field('self.id')]);
            $query->groupBy('self.id');
        }

        return $query
            ->where($criteria)
            ->limit($count)
            ->offset($offset);
    }

    private function getPostListByRating(DbQuery $query): array
    {
        $postData = $query
            ->orderBy(['sort_value' => 'DESC', 'created_at' => 'DESC'])
            ->getRows();

        return array_column($postData, 'id');
    }

    private function getPostListByCreationDate(DbQuery $query): array
    {
        $postData = $query
            ->orderBy(['created_at' => 'DESC'])
            ->getRows();

        return array_column($postData, 'id');
    }

    private function getPostListByPopularity(DbQuery $query, int $limit, int $offset): array
    {
        return $this->getPostIdsByFormula($query, $limit, $offset, 60);
    }

    private function getPostListByMainPage(DbQuery $query, int $limit, int $offset): array
    {
        return $this->getPostIdsByFormula($query, $limit, $offset, 130);
    }

    private function getPostIdsByFormula(DbQuery $query, int $limit, int $offset, int $ageRatio): array
    {
        $postData = $query
            ->select(['self.id', 'self.sort_value', 'self.created_at'])
            ->limit()
            ->offset()
            ->getRows();

        foreach ($postData as $key => $value) {
            $postData[$key]['popularity'] = $this->calcValue($value['created_at'], $value['sort_value'], $ageRatio);
        }

        usort($postData, function ($a, $b) {
            return $b['popularity'] - $a['popularity'];
        });

        $postData = array_slice($postData, $offset, $limit);

        return array_column($postData, 'id');
    }

    private function calcValue(string $createdAt, int $rating, int $ageRatio): int
    {
        $postAge = time() - dt($createdAt)->getTimestamp();
        // Устанавливаем минимальное значение возраста при расчете sort_value
        $postAge = max($postAge, 6 * DateTime::HOUR);
        $signValue = $rating > 0 ? 1 : -1;
        $postRating = 0 === $rating ? 1 : $rating;
        $result = 1000 * ($ageRatio / pow($postAge, 1 / 4) + log(abs($postRating)) * $signValue);

        return round($result);
    }

    private function prepareList(array $posts): array
    {
        $this->em->load($posts, 'game', 'user', 'comment_count', 'rating', 'link', 'user_changed', 'user_access', 'categories', 'file_download_count');
        $games = [];
        $users = [];
        foreach ($posts as $post) {
            if (null !== $post->gameId) {
                $games[$post->gameId] = $post->game;
            }
            if (null !== $post->userId) {
                $users[$post->userId] = $post->user;
            }
        }
        $this->em->load($games, 'link');
        $this->em->load($users, 'link');

        return $posts;
    }
}
