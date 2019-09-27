<?php

namespace JoeyRush\EagerJoins;

use Fico7489\Laravel\EloquentJoin\EloquentJoinBuilder as BaseEloquentJoinBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class EloquentJoinBuilder extends BaseEloquentJoinBuilder
{
    /**
     * Determine whether to hydrate the relationship models and strip out the non-related attributes from the base model.
     * Performance will be *significantly* better on large collections when this is disabled, but it means you won't be able
     * to access relationship data the normal way. E.g. $post->category->name would instead have to be $post->category_name
     *
     * @var boolean
     */
    protected $populateRelations = false;

    /**
     * An array of relationship names that we want to join (instead of performing a separate query).
     *
     * Nested relations can use dot notation (e.g. 'post.category.creator')
     * @var array
     */
    protected $joinRelations = [];

    /**
     * A blacklist of relationship names to exclude from the next query.
     * This is useful if you setup default join relations on your model but would like to disable them for a particular query.
     * @var array
     */
    protected $excluded = [];

    /**
     * A cached list of model classes required for populating the related models.
     * @var array
     */
    private $modelClasses = [];

    private $lowerCaseModelName = null;

    public function include(string $relations, $leftJoin = null)
    {
        $this->joinRelations[] = $relations;

        $relationParts = explode('.', $relations);
        if (count($relationParts) > 1) {
            $firstRelation = $relationParts[0];
            $relatedModel = $this->getModel()->$firstRelation();
            if (! $relatedModel instanceof BelongsTo && ! $relatedModel instanceof HasOne) {
                throw new \Exception('Cannot join nested one-to-many or many-to-many relationships via include()');
            }
        }

        foreach ($relationParts as $relation) {
            if (empty($model)) {
                $model = $this;
            }

            $relatedRelation = $model->getModel()->$relation();
            $relatedModel = $relatedRelation->getRelated();
            // @todo: we currently either rely on the model having a $fields property, or fallback to just pulling out the primary key
            // .. but we could used a cached schema instead (the schema cache would get cleared after new migrations are run) - this would be good as a standalone package
            foreach ($relatedModel->fields ?? [$relatedModel->getKeyName()] as $field) {
                $this->addSelect($relatedModel->getTable() . ".$field as {$relation}_TEMP_{$field}");
            }

            if (!$relatedRelation instanceof BelongsTo && !$relatedRelation instanceof HasOne) {
                $this->groupBy($relation . '.' . $relatedModel->getKeyName());
            }

            $model = $relatedModel;
        }

        return $this->joinRelations($relations, $leftJoin);
    }

    public function exclude($relationToExclude)
    {
        $this->excluded[] = $relationToExclude;

        return $this;
    }

    public function populateRelations()
    {
        $this->populateRelations = true;

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function get($columns = ['*'])
    {
        $baseModel = $this->getModel();
        $this->lowerCaseModelName = Str::lower(class_basename($baseModel));

        if ($this->getQuery()->limit && $joinRelation = $this->hasManyJoinRelation()) {
            throw new \Exception("Cannot limit query when joining a one-or-many-to-many relationship ({$this->lowerCaseModelName}.$joinRelation). Try using with() instead of");
        }

        $this->modelClasses[$this->lowerCaseModelName] = get_class($baseModel);
        $this->loadDefaultJoins($baseModel);

        $collection = parent::get($columns);
        return empty($this->joinRelations)
            ? $collection
            : $this->normalizeModels($collection);
    }

    public function normalizeModels($collection)
    {
        $baseModel = $this->getModel();

        if (!$this->populateRelations) {
            return $collection->map(function ($model) {
                $attributes = [];
                foreach ($model->getAttributes() as $attribute => $value) {
                    $attributes[str_replace('_TEMP_', '_', $attribute)] = $value;
                }
                return $model->setRawAttributes($attributes);
            });
        }

        foreach ($this->joinRelations as $relations) {
            $relations = explode('.', $relations);
            $firstRelation = $relations[0];
            $relatedModel = $baseModel->$firstRelation();
            
            if ($relatedModel instanceof BelongsTo || $relatedModel instanceof HasOne) {
                $collection = $this->hydrateOneToOneRelationships($collection, $relations);
            } else {
                $collection = $this->hydrateManyRelationships($collection, $relations[0], $relatedModel->getRelated());
            }
        }

        return $collection;
    }

    private function loadDefaultJoins($baseModel)
    {
        foreach (Arr::wrap($baseModel->include) as $relation) {
            if (!in_array($relation, $this->excluded)) {
                $this->include($relation, $baseModel->leftJoin);
            }
        }
    }

    private function getModelTree($nestedAttributes, $modelClasses)
    {
        $arr = [];
        foreach ($nestedAttributes as $modelNameLowercase => $attributes) {
            $arr[] = $this->getModelFromAttributes($attributes, $modelClasses, $modelNameLowercase);
        }

        return $arr[0];
    }

    private function getModelFromAttributes($attributes, $modelClasses, $modelNameLowercase)
    {
        $relatedModels = [];

        foreach ($attributes as $attr => $value) {
            if (isset($modelClasses[$attr]) && is_array($value)) {
                $relatedModels[$attr] = $value;
            }
        }

        $attributesWithoutRelations = array_filter($attributes, function ($attribute) use ($relatedModels) {
            return !in_array($attribute, array_keys($relatedModels));
        }, ARRAY_FILTER_USE_KEY);

        $modelClasses[$modelNameLowercase]::unguard();
        $model = new $modelClasses[$modelNameLowercase]($attributesWithoutRelations);
        $modelClasses[$modelNameLowercase]::reguard();

        foreach ($relatedModels as $relationshipName => $relatedModelAttributes) {
            $model->setRelation($relationshipName, $this->getModelFromAttributes($relatedModelAttributes, $modelClasses, $relationshipName));
        }

        // Important flag that allows us to be able to save any of the models (providing they exist) without having to reload them
        $model->exists = $model->{$model->getKeyName()} ? true : false;
        return $model;
    }

    private function getModelClasses($relations)
    {
        collect($relations)
            ->eachCons(2)
            ->each(function ($prevAndNext) {
                [$prev, $next] = $prevAndNext;

                if (empty($this->modelClasses[$prev])) {
                    $this->modelClasses[$prev] = get_class($this->getModel()->$prev()->getRelated());
                }

                if (empty($this->modelClasses[$next])) {
                    $model = new $this->modelClasses[$prev]();
                    $this->modelClasses[$next] = get_class($model->$next()->getRelated());
                }
            });

        return $this->modelClasses;
    }

    private function getNestedAttributes($groupedAttributes, $relations)
    {
        $arr = [];

        // ['post', category', 'creator', ...]
        foreach ($relations as $key => $relation) {
            $arrayKey = isset($arrayKey) ? $arrayKey . '.' . $relation : $relation;

            array_set(
                $arr,
                $arrayKey,
                $groupedAttributes[$relation]->mapWithKeys(function ($value, $attribute) use ($relations) {
                    if (in_array(Str::before($attribute, '_TEMP_'), $relations)) {
                        return [Str::after($attribute, '_TEMP_') => $value];
                    }

                    return [$attribute => $value];
                })->toArray()
            );
        }

        return $arr;
    }

    public function hasManyJoinRelation()
    {
        $baseModel = $this->getModel();
        $includedRelations = array_merge($this->joinRelations, Arr::wrap($baseModel->include));
        $joinRelations = array_diff($includedRelations, $this->excluded);

        foreach ($joinRelations as $joinRelation) {
            $firstRelation = explode('.', $joinRelation)[0];
            $relatedRelation = $baseModel->$firstRelation();

            if (!$relatedRelation instanceof BelongsTo && !$relatedRelation instanceof HasOne) {
                return $firstRelation;
            }
        }

        return false;
    }

    private function getRelationFields($model, $joinRelation)
    {
        return collect($model->getAttributes())->filter(function ($value, $field) use ($joinRelation) {
            return Str::startsWith($field, $joinRelation . "_TEMP_");
        })->mapWithKeys(function ($value, $field) use ($model) {
            //unset($model->$field);
            return [Str::after($field, "_TEMP_") => $value];
        })->filter()->toArray();
    }

    private function removeRelationFieldsFromBaseModel(&$model, $joinRelation)
    {
        // Clean up the temporary attributes on the main model
        foreach ($model->getAttributes() as $attribute => $value) {
            if (Str::contains($attribute, $joinRelation . '_TEMP_')) {
                unset($model->$attribute);
            }
        }
    }

    private function hydrateManyRelationships($collection, $joinRelation, $relatedModel)
    {
        $relatedModels = $collection->map(function ($model) use ($joinRelation, $relatedModel) {
            $model = $relatedModel::make($this->getRelationFields($model, $joinRelation));
            $model->exists = true;
            return $model;
        });

        $primaryKey = $this->getModel()->getKeyName();

        // Make sure to use the right collection type, i.e. if a user has defined a custom collection for the related model.
        $collectionClass = get_class($relatedModel->newCollection());
        $relatedModels = new $collectionClass($relatedModels);
        $relatedModelPrimaryKey = $relatedModel->getKeyName();

        foreach ($collection as $model) {
            $relatedIds = $collection->where($primaryKey, $model->$primaryKey)->pluck($joinRelation . '_TEMP_' . $relatedModelPrimaryKey)->filter();

            if ($relatedIds->isNotEmpty()) {
                $relationshipCollection = $relatedModels->whereIn($relatedModelPrimaryKey, $relatedIds)->values();
            }
            $model->setRelation($joinRelation, $relationshipCollection ?? $relatedModel->newCollection([]));
        }

        foreach ($collection as $model) {
            $this->removeRelationFieldsFromBaseModel($model, $joinRelation);
        }

        return $collection->unique($primaryKey)->values();
    }

    private function hydrateOneToOneRelationships($collection, $relations)
    {
        foreach ($collection as $key => $model) {
            $groupedAttributes = collect($model->getAttributes())
                ->groupBy(function ($value, $attribute) use ($relations) {
                    // e.g. "category_TEMP_id" and "category_TEMP_name" will be grouped together under a "category" key.
                    // This only applies to attributes within the current relation(s). I.e. if we include('category.creator'), we won't touch tags_TEMP_id
                    $attributeRelationName = Str::before($attribute, '_TEMP_');
                    return Str::contains($attribute, '_TEMP_') && in_array($attributeRelationName, $relations)
                        ? $attributeRelationName
                        : $this->lowerCaseModelName;
                }, $preserveKeys = true);

            // Prepend the original model.. so Post::include('category.creator') would yield ['post', 'category', 'creator']
            $flatRelationList = array_merge([$this->lowerCaseModelName], $relations);

            $updatedModel = $this->getModelTree(
                $this->getNestedAttributes($groupedAttributes, $flatRelationList),
                $this->getModelClasses($flatRelationList)
            );

            // Preserve any existing relations that were on the model before this iteration
            $updatedModel->setRelations($updatedModel->getRelations() + $model->getRelations());

            // Swap out the model in the collection for our new one with the correct nested relationships and normalized attributes
            $collection->offsetSet($key, $updatedModel);
        }

        return $collection;
    }
}
