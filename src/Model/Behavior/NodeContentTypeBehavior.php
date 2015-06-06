<?php

namespace NodesDatasource\Model\Behavior;

use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\Table;

class NodeContentTypeBehavior extends Behavior
{

    public function __construct(Table $table, array $config = [])
    {
        $table->table('nodes');

        parent::__construct($table, $config);
    }

    public function beforeFind(Event $event, Query $query)
    {
        return $query->where([
            'type' => $this->config('type')
        ]);
    }

}
