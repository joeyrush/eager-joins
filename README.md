# Eager Join
Eager-load your eloquent relationships in Laravel using joins instead of separate queries.

## Installation
```
composer require joeyrush/eager-joins
```

## Setup
1. Attach the trait to your individual models or base model:

```php
class Post extends Model
{
    use \JoeyRush\EagerJoins\JoinRelations;

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
```

2. Specify which `$fields` should be loaded in the joins on the related model:

```php
class Category extends Model
{
    /** @var array */
    public $fields = ['id', 'name'];
}
```

And now you can eager-load using joins by calling the `include($relationName)` method on your model/query builder wherever you would previously call the `with($relationName)` method.

```php
// The following will return all posts, each with their associated category pre-loaded.
$posts = Post::include('category')->get();
```

## Examples

### Hydrate related models
By default, the relationship fields will be stored on the original model, e.g. the following will return `Post` models with `category_name` and `category_id` stored directly on the model.

```php
$posts = Post::include('category')->get();
```

If you'd like to have the related models hydrated and linked up correctly, you can call `populateRelationships()`

> Note: the larger the data set, the more of a performance penalty this will incur.

```php
$posts = Post::populateRelationships()->include('category')->get();
```

### Nested relationships
You can use dot notation to eager-load nested relations. **This only works on HasOne and BelongsTo relationships**
```php
$posts = Post::include('category.creator.profile')->get();
```

### Multiple Eager-Loads
You can eager load as many relationships as needed
```php
$posts = Post::query()
	->include('category.creator.profile')
	->include('tags')
	->include('author')
	->first();
```

## Limitations
1. You cannot limit or paginate your queries when eager-joining `HasMany` / `BelongsToMany` relationships due to SQL limitations
2. You cannot eager-load nested `HasMany` or `BelongsToMany` relationships.
2. You cannot join on multiple relationships with the same table **yet**.