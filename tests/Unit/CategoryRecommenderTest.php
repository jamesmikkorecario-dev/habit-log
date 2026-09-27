<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\CategoryAlias;
use App\Services\CategoryRecommender;
use App\Support\NormalizedName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CategoryRecommenderTest extends TestCase
{
    #[DataProvider('names')]
    public function test_normalization(string $input, string $expected): void
    {
        $this->assertSame($expected, NormalizedName::from($input));
    }

    public static function names(): array
    {
        return [['  HEALTH---& fitness ', 'health fitness'], ['読書 習慣', '読書 習慣'], [' 0 ', '0'], ['---', ''], ["Work\t and\nRest", 'work and rest']];
    }

    private function category(int $id, string $name, array $aliases = []): Category
    {
        $category = new Category(['name' => $name, 'normalized_name' => NormalizedName::from($name)]);
        $category->id = $id;
        $category->setRelation('aliases', collect(array_map(fn ($alias) => new CategoryAlias(['normalized_alias' => NormalizedName::from($alias)]), $aliases)));

        return $category;
    }

    public function test_exact_name_then_alias_then_token_overlap_and_three_result_limit(): void
    {
        $categories = collect([
            $this->category(5, 'Z read books', ['read', 'books']),
            $this->category(4, 'A read'),
            $this->category(3, 'Learning', ['read books']),
            $this->category(2, 'Read books'),
            $this->category(1, 'Unrelated'),
        ]);
        $ranker = new CategoryRecommender;
        $this->assertSame([2, 3, 5], $ranker->rank('READ--BOOKS', $categories)->pluck('id')->all());
        $this->assertSame([2, 3, 5], $ranker->rank('READ--BOOKS', $categories->reverse())->pluck('id')->all());
        $this->assertCount(0, $ranker->rank('reader', $categories));
        $this->assertCount(0, $ranker->rank('', $categories));
    }

    public function test_ties_use_normalized_name_then_id_and_results_are_distinct(): void
    {
        $categories = collect([$this->category(3, 'Z read'), $this->category(2, 'A read'), $this->category(1, 'A read'), $this->category(1, 'A read')]);
        $this->assertSame([1, 2, 3], (new CategoryRecommender)->rank('read', $categories)->pluck('id')->all());
    }
}
