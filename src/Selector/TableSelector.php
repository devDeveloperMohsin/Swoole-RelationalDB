<?php
/*
 *  This file is a part of small-swoole-db
 *  Copyright 2023 - Sébastien Kus
 *  Under GNU GPL V3 licence
 */

namespace Small\SwooleDb\Selector;

use Small\SwooleDb\Core\Bean\IndexFilter;
use Small\SwooleDb\Core\Column;
use Small\SwooleDb\Core\Enum\Operator;
use Small\SwooleDb\Core\Record;
use Small\SwooleDb\Core\RecordCollection;
use Small\SwooleDb\Core\Resultset;
use Small\SwooleDb\Exception\NotFoundException;
use Small\SwooleDb\Registry\TableRegistry;
use Small\SwooleDb\Selector\Bean\Bracket;
use Small\SwooleDb\Selector\Bean\Condition;
use Small\SwooleDb\Selector\Bean\OrderByCollection;
use Small\SwooleDb\Selector\Bean\OrderByField;
use Small\SwooleDb\Selector\Bean\ResultTree;
use Small\SwooleDb\Selector\Enum\BracketOperator;
use Small\SwooleDb\Selector\Enum\ConditionElementType;
use Small\SwooleDb\Selector\Enum\ConditionOperator;
use Small\SwooleDb\Selector\Exception\SyntaxErrorException;

class TableSelector
{

    public int|null $page = null;
    public int|null $pageSize = null;

    /** @var Join[] */
    protected array $joins = [];

    protected Bracket|null $where = null;

    protected OrderByCollection $orderBy;

    public function __construct(
        protected string $from,
        protected string|null $alias = null
    ) {

        $this->orderBy = new OrderByCollection();

        if ($this->alias == null) {
            $this->alias = $this->from;
        }

    }

    public function addOrderBy(OrderByField $orderByField): self
    {

        $tableFound = false;
        if (
            $this->alias == $orderByField->alias
        ) {

            $tableFound = true;

            if (
                $orderByField->field != Column::KEY_COL_NAME &&
                !TableRegistry::getInstance()
                    ->getTable($this->from)
                    ->hasColumn($orderByField->field)
            ) {

                throw new SyntaxErrorException('Field ' .
                    $orderByField->field . ' in table aliased ' .
                    $orderByField->alias
                );

            }

        } else {

            foreach ($this->joins as $join) {

                if ($join->getAlias() == $orderByField->alias) {

                    $tableFound = true;

                    if (
                        $orderByField->field != Column::KEY_COL_NAME &&
                        !TableRegistry::getInstance()
                            ->getTable($join->getToTableName())
                            ->hasColumn($orderByField->field)
                    ) {

                        throw new SyntaxErrorException(
                            'Field ' . $orderByField->field .
                            ' in table aliased ' . $orderByField->alias
                        );

                    }

                    break;

                }

            }

        }

        if (!$tableFound) {

            throw new SyntaxErrorException(
                'Table alias ' . $orderByField->alias . ' is not found in selector.'
            );

        }

        $this->orderBy[] = $orderByField;

        return $this;

    }

    /**
     * Paginate query
     * @param int $page
     * @param int $pageSize
     * @return $this
     */
    public function paginate(int $page, int $pageSize): self
    {

        $this->page = $page;
        $this->pageSize = $pageSize;

        return $this;

    }

    /**
     * @param string $alias
     * @return string
     * @throws NotFoundException
     */
    public function getTableForAlias(string $alias): string
    {

        if ($alias == ($this->alias ?? $this->from)) {
            return $this->from;
        }

        foreach ($this->joins as $join) {
            if ($alias == $join->getAlias()) {
                return $join->getToTableName();
            }
        }

        throw new NotFoundException('Alias ' . $alias . ' not found');

    }

    /**
     * @return IndexFilter[][]
     * @throws NotFoundException
     * @throws SyntaxErrorException
     * @throws \Small\SwooleDb\Exception\TableNotExists
     */
    public function getOptimisations(): array
    {

        $indexFilters = [];

        $broken = false;
        $keyToFilter = [];
        foreach ($this->where()->getOperators() as $key => $operator) {

            if ($operator == BracketOperator::and) {

                if ($this->where()->getConditions()[$key] instanceof Condition) {
                    $keyToFilter[] = $key;
                }

            } else {
                $broken = true;
                break;
            }

        }

        if (!$broken) {
            if (isset($key) && $this->where()->getConditions()[$key + 1] instanceof Condition) {
                $keyToFilter[] = $key + 1;
            }
        }

        $createFilter = function (string $alias, int $key, string $field, string|int|float|null $value): IndexFilter|null {

            if (!$this->where()->getConditions()[$key] instanceof Condition) {
                throw new \LogicException('$key don\'t point on ' . Condition::class);
            }

            $operator = match ($this->where()->getConditions()[$key]->getOperator()) {
                ConditionOperator::equal => Operator::equal,
                ConditionOperator::inferior => Operator::inferior,
                ConditionOperator::inferiorOrEqual => Operator::inferiorOrEqual,
                ConditionOperator::superior => Operator::superior,
                ConditionOperator::superiorOrEqual => Operator::superiorOrEqual,
                default => null,
            };

            if ($operator === null) {
                return null;
            }

            if (!TableRegistry::getInstance()->getTable($this->getTableForAlias($alias))->hasIndex($field)) {
                return null;
            }

            $filter = new IndexFilter(
                $operator,
                $field,
                $value,
            );

            return $filter;

        };

        foreach ($keyToFilter as $key) {

            if ($this->where()->getConditions()[$key] instanceof Condition) {

                if ($this->where()->getConditions()[$key]->getLeftElement()->getType() == ConditionElementType::var) {
                    if ($this->where()->getConditions()[$key]->getRightElement()?->getType() == ConditionElementType::const) {

                        $table = $this->where()->getConditions()[$key]->getLeftElement()->getTable() ?? '';
                        $field = $this->where()->getConditions()[$key]->getLeftElement()->getValue() ?? '';
                        $value = $this->where()->getConditions()[$key]->getRightElement()?->getValue() ?? '';

                        if (!is_string($field)) {
                            throw new SyntaxErrorException('Field must be string');
                        }

                        if (!is_array($value)) {

                            $filter = $createFilter($table, $key, $field, $value);
                            if ($filter !== null) {
                                $indexFilters[$table][] = $filter;
                            }

                        }

                    }
                }

                if ($this->where()->getConditions()[$key]->getRightElement()?->getType() == ConditionElementType::var) {
                    if ($this->where()->getConditions()[$key]->getLeftElement()->getType() == ConditionElementType::const) {

                        $table = $this->where()->getConditions()[$key]->getRightElement()?->getTable() ?? '';
                        $field = $this->where()->getConditions()[$key]->getRightElement()?->getValue();
                        $value = $this->where()->getConditions()[$key]->getLeftElement()->getValue();

                        if (!is_string($field)) {
                            throw new SyntaxErrorException('Field must be string');
                        }

                        if (!is_array($value)) {
                            $filter = $createFilter($table, $key, $field, $value);
                            if ($filter !== null) {
                                $indexFilters[$table][] = $filter;
                            }
                        }

                    }
                }

            }

        }

        return $indexFilters;

    }

    public function join(string $from, string $foreignKeyName, string $alias = null): self
    {

        if ($alias === null) {
            $alias = $from;
        }

        if (array_key_exists($alias, $this->joins)) {
            throw new SyntaxErrorException('The join alias \'' . $alias . '\' already exists');
        }

        if (!array_key_exists($from, $this->joins) && $from != $this->from) {
            throw new SyntaxErrorException('The join from alias \'' . $from . '\' does not exists');
        }

        $this->joins[$from . '/' . $alias] = new Join($from, $foreignKeyName, $alias);

        return $this;

    }

    public function where(): Bracket
    {

        if ($this->where === null) {
            $this->where = new Bracket();
        }

        return $this->where;

    }

    /**
     * Execute query
     * @return Resultset
     * @throws \Small\SwooleDb\Exception\TableNotExists
     */
    public function execute(): Resultset
    {

        $fromTable = TableRegistry::getInstance()->getTable($this->from);

        if ($fromFilters = $this->getOptimisations()[$fromTable->getName()]) {
            $records = $fromTable->filterWithIndex($fromFilters);
        } else {
            $records = $fromTable;
        }

        $populatedRecords = new Resultset();
        foreach ($records as $record) {

            $populated = new Resultset([new RecordCollection([$fromTable->getName() => $record])]);

            if (count($this->joins) > 0) {

                $populatedWithJoin = [];
                foreach ($this->joins as $join) {

                    foreach ($populated as $item) {

                        $populatedWithJoin = new Resultset();
                        foreach ($join->get($item) as $itemJoined) {
                            $populatedWithJoin[] = $item->merge(new RecordCollection($itemJoined));

                        }

                    }

                    $populated = $populatedWithJoin;

                }

            }

            $populatedRecords->merge($populated, true);

        }

        if ($this->orderBy->count() > 0) {
            echo '1';
            $populatedRecords->orderBy($this->orderBy);
        }

        $result = new Resultset();
        $from = null;
        $rowNum = 0;
        if ($this->pageSize !== null) {
            $from = $this->pageSize * ($this->page - 1);
        }
        foreach ($populatedRecords as $record) {

            $record = $this->applyAliasesOnRecord($record);

            if ($this->where()->validateBracket($record)) {

                if ($from === null || $rowNum >= $from) {

                    $result[] = $record;

                    if ($this->pageSize !== null && $result->count() == $this->pageSize) {
                        break;
                    }

                }

                $rowNum++;
            }

        }

        return $result;

    }

    protected function applyAliasesOnRecord(RecordCollection $record): RecordCollection
    {

        $translated = new RecordCollection();
        foreach ($record as $item) {

            if ($item->getTable()->getName() == $this->from) {
                $translated[$this->alias] = $item;
            }

            foreach ($this->joins as $join) {
                if ($item->getTable()->getName() == $join->getToTableName()) {
                    $translated[$join->getAlias()] = $item;
                }
            }

        }

        return $translated;

    }

}