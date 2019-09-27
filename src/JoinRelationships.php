<?php

namespace JoeyRush\EagerJoins;

use Fico7489\Laravel\EloquentJoin\Traits\ExtendRelationsTrait;
use JoeyRush\EagerJoins\EloquentJoinBuilder;

trait JoinRelations
{
    use ExtendRelationsTrait;

    public function newEloquentBuilder($query)
    {
        $newEloquentBuilder = new EloquentJoinBuilder($query);

        if (isset($this->useTableAlias)) {
            $newEloquentBuilder->setUseTableAlias($this->useTableAlias);
        }

        if (isset($this->appendRelationsCount)) {
            $newEloquentBuilder->setAppendRelationsCount($this->appendRelationsCount);
        }

        if (isset($this->leftJoin)) {
            $newEloquentBuilder->setLeftJoin($this->leftJoin);
        }

        if (isset($this->aggregateMethod)) {
            $newEloquentBuilder->setAggregateMethod($this->aggregateMethod);
        }

        return $newEloquentBuilder;
    }
}
