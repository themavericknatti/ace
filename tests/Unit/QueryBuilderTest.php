<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ace\QueryBuilder;
use Ace\Model;

class ArticleModel extends Model
{
    public static function tableName(): string { return 'articles'; }
    public function primaryKey(): string { return 'id'; }
    public function rules(): array { return []; }
}

class QueryBuilderTest extends TestCase
{
    public function test_to_sql_generates_valid_select_query(): void
    {
        $qb = ArticleModel::query()
            ->select('articles.id', 'articles.title', 'users.name')
            ->leftJoin('users', 'articles.user_id', '=', 'users.id')
            ->where('articles.status', 'published')
            ->where('articles.views', '>', 100)
            ->whereIn('articles.category_id', [1, 2, 3])
            ->whereNull('articles.deleted_at')
            ->whereBetween('articles.created_at', '2026-01-01', '2026-12-31')
            ->orderBy('articles.id', 'DESC')
            ->take(15)
            ->skip(30);

        $sql = $qb->toSql();

        $this->assertStringContainsString('SELECT `articles`.`id`, `articles`.`title`, `users`.`name` FROM `articles`', $sql);
        $this->assertStringContainsString('LEFT JOIN `users` ON `articles`.`user_id` = `users`.`id`', $sql);
        $this->assertStringContainsString('WHERE (`articles`.`status` = :p_0_articlesstatus AND `articles`.`views` > :p_1_articlesviews', $sql);
        $this->assertStringContainsString('`articles`.`category_id` IN (:in_2_0, :in_2_1, :in_2_2)', $sql);
        $this->assertStringContainsString('`articles`.`deleted_at` IS NULL', $sql);
        $this->assertStringContainsString('`articles`.`created_at` BETWEEN :btwn_5_min AND :btwn_5_max', $sql);
        $this->assertStringContainsString('ORDER BY `articles`.`id` DESC', $sql);
        $this->assertStringContainsString('LIMIT 15', $sql);
        $this->assertStringContainsString('OFFSET 30', $sql);
    }

    public function test_where_clause_supports_both_two_and_three_argument_syntaxes(): void
    {
        $qb1 = ArticleModel::query()->where('status', 'active');
        $this->assertStringContainsString('`status` = :p_0_status', $qb1->toSql());
        $this->assertEquals('active', $qb1->getBindings()['p_0_status']);

        $qb2 = ArticleModel::query()->where('score', '>=', 50);
        $this->assertStringContainsString('`score` >= :p_0_score', $qb2->toSql());
        $this->assertEquals(50, $qb2->getBindings()['p_0_score']);

        // Legacy Ace syntax: where(column, value, operator)
        $qb3 = ArticleModel::query()->where('score', 99, '<=');
        $this->assertStringContainsString('`score` <= :p_0_score', $qb3->toSql());
        $this->assertEquals(99, $qb3->getBindings()['p_0_score']);
    }

    public function test_joins_support_inner_left_and_right(): void
    {
        $qb = ArticleModel::query()
            ->join('categories', 'articles.cat_id', '=', 'categories.id')
            ->rightJoin('tags', 'articles.tag_id', '=', 'tags.id');

        $sql = $qb->toSql();
        $this->assertStringContainsString('INNER JOIN `categories` ON `articles`.`cat_id` = `categories`.`id`', $sql);
        $this->assertStringContainsString('RIGHT JOIN `tags` ON `articles`.`tag_id` = `tags`.`id`', $sql);
    }

    public function test_or_where_combines_properly(): void
    {
        $qb = ArticleModel::query()
            ->where('status', 'draft')
            ->orWhere('status', 'archived');

        $sql = $qb->toSql();
        $this->assertStringContainsString('WHERE (`status` = :p_0_status) OR `status` = :p_1_status', $sql);
    }
}
