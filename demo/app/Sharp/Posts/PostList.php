<?php

namespace App\Sharp\Posts;

use App\Models\Post;
use App\Sharp\Posts\Filters\AuthorFilter;
use App\Sharp\Utils\DateTimeCustomTransformer;
use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\Fields\EntityListFieldsLayout;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;

class PostList extends SharpEntityList
{
    public function buildListConfig(): void
    {
        $this->configurePaginated()
            ->configureDefaultSort('published_at', 'desc')
            ->configureSearchable();
    }

    protected function buildListFields(EntityListFieldsContainer $fieldsContainer): void
    {
        $fieldsContainer
            ->addField(
                EntityListField::make("title")
                    ->setLabel("Title")
            )
            ->addField(
                EntityListField::make("author:name")
                    ->setLabel("Author")
                    ->setSortable()
            )
            ->addField(
                EntityListField::make("published_at")
                    ->setLabel("Published at")
                    ->setSortable()
            );
    }
    
    protected function buildListLayout(EntityListFieldsLayout $fieldsLayout): void
    {
        $fieldsLayout
            ->addColumn("title", 5)
            ->addColumn("author:name", 3)
            ->addColumn("published_at", 4);
    }

    protected function buildListLayoutForSmallScreens(EntityListFieldsLayout $fieldsLayout): void
    {
        $fieldsLayout
            ->addColumn("title", 6)
            ->addColumn("published_at", 6);
    }
    
    protected function getFilters(): ?array
    {
        return [
            AuthorFilter::class
        ];
    }

    protected function getListData(): array|Arrayable
    {
        $posts = Post::select('posts.*')
            ->with("author")

            // Handle filters
            ->when(
                $this->queryParams->filterFor(AuthorFilter::class),
                function(Builder $builder, int $authorId) {
                    $builder->where('author_id', $authorId);
                }
            )
            
            // Handle search words
            ->when(
                $this->queryParams->hasSearch(), 
                function(Builder $builder) {
                    foreach ($this->queryParams->searchWords() as $word) {
                        $builder->where(function ($query) use ($word) {
                            $query
                                ->orWhere("title->fr", "like", $word)
                                ->orWhere("title->en", "like", $word);
                        });
                    }
                }
            )
            
            // Handle sorting
            ->when(
                $this->queryParams->sortedBy() === 'author:name', 
                function(Builder $builder) {
                    $builder
                        ->leftJoin('users', 'posts.author_id', '=', 'users.id')
                        ->orderBy('users.name', $this->queryParams->sortedDir());
                },
                function(Builder $builder) {
                    $builder->orderBy('published_at', $this->queryParams->sortedDir() ?: 'desc');
                },
            );
        
        return $this
            ->setCustomTransformer("title", function ($value, Post $instance) {
                return sprintf(
                    '<div><strong>fr</strong> %s</div><div><strong>en</strong> %s</div>',
                    $instance->getTranslation('title', 'fr'),
                    $instance->getTranslation('title', 'en'),
                );
            })
            ->setCustomTransformer("published_at", DateTimeCustomTransformer::class)
            ->transform($posts->paginate(20));
    }
}