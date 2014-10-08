<?php

class CroogoNodesSource extends DataSource {

	/**
	 * @var Node
	 */
	public $Node = null;

	public function __construct($config = array()) {
		parent::__construct($config);

		$this->Node = ClassRegistry::init('Nodes.Node');
		$this->TypeField = ClassRegistry::init('CustomFields.TypeField');

		$this->columns = $this->Node->getDataSource()->columns;
	}

	public function listSources($data = null) {
		return null;
	}

	public function describe($model) {
		$schema = $this->Node->schema();
		unset($schema['type']);

		$endData = array_splice($schema, 7);

		$metaFields = $this->Node->Meta->find('list', array(
			'fields'     => array($this->Node->Meta->alias . '.key'),
			'conditions' => array(
				'Node.type' => Inflector::singularize($model->table)
			),
			'recursive'  => 0
		));

		$customFields = $this->TypeField->find('all', array(
			'conditions' => array(
				$this->TypeField->alias . '.type_alias' => Inflector::singularize($model->table)
			)
		));

		foreach ($metaFields as $field) {
			$schema[$field] = array(
				'type' => 'text',
				'null' => false,
				'default' => null,
				'length' => null,
				'collate' => 'utf8_unicode_ci',
				'charset' => 'utf8',
				'custom'  => true,
			);
		}

		foreach ($customFields as $field) {
			$schema[$field['TypeField']['name']] = array(
				'type' => $field['TypeField']['type'],
				'null' => !$field['TypeField']['required'],
				'default' => $field['TypeField']['default'],
				'length' => null,
				'collate' => 'utf8_unicode_ci',
				'charset' => 'utf8',
				'custom'  => true,
			);
		}

		$schema += $endData;

		return $schema;
	}

	public function calculate(Model $model, $func, $params = array()) {
		return 'COUNT';
	}

	public function read(Model $Model, $queryData = array(), $recursive = null) {
		$queryType = 'all';
		$query = array();

		if (!isset($queryData['recursive'])) {
			$queryData['recursive'] = $Model->recursive;
		}

		$query['conditions'] = $this->__rebuildConditions($Model, $queryData['conditions']);
		$query['conditions'][$this->Node->alias . '.type'] = Inflector::singularize($Model->table);

		foreach ($queryData['order'] as $orderData) {
			if (!$orderData) {
				continue;
			}

			foreach ($orderData as $field => $condition) {
				list ($field, $model) = array_reverse(explode('.', $field));
				$query['order'][$this->Node->alias . '.' . $field] = $condition;
			}
		}

		if (is_array($queryData['fields'])) {
			foreach ($queryData['fields'] as $field) {
				if (!$field) {
					continue;
				}

				if (strstr($field, '.')) {
					list ($field, $model) = array_reverse(explode('.', $field));
				}
				$query['fields'][] = $this->Node->alias . '.' . $field;
			}
		} else {
			if ($queryData['fields'] === 'COUNT') {
				$queryType = 'count';
			}
		}

		if ($queryData['limit']) {
			$query['limit'] = $queryData['limit'];
		}
		if ($queryData['offset']) {
			$query['offset'] = $queryData['offset'];
		}

//		debug($query);
		$nodes = $this->Node->find($queryType, $query);
//		debug($nodes);

		if ($queryData['fields'] === 'COUNT') {
			return array(array(array('count' => $nodes)));
		}

		$fields = array_keys($this->describe($Model));

		$modelData = array();
		foreach ($nodes as $node) {
			$modelEntry = array();
			$modelEntry[$Model->alias] = $node['Node'];
			unset($modelEntry[$Model->alias]['url']);
			unset($modelEntry[$Model->alias]['type']);

			foreach ($fields as $field) {
				if (isset($modelEntry[$Model->alias][$field])) {
					continue;
				}

				$modelEntry[$Model->alias][$field] = null;
			}

			$relations = array_merge(
				array_keys($Model->belongsTo),
				array_keys($Model->hasOne),
				array_keys($Model->hasMany)
			);
			foreach ($relations as $relation) {
				if (!isset($node[$relation])) {
					continue;
				}

				$modelEntry[$relation] = $node[$relation];
			}


			if (isset($modelEntry['Meta'])) {
				foreach ($modelEntry['Meta'] as $meta) {
					$modelEntry[$Model->alias][$meta['key']] = $meta['value'];
				}
			}

			$modelData[] = $modelEntry;
		}

		if ($queryData['recursive'] > -1) {
			foreach ($Model->_associations as $type) {
				foreach ($Model->{$type} as $assoc => $assocData) {
					$LinkModel = $Model->{$assoc};

					$stack = array($assoc);
					$this->queryAssociation($Model, $LinkModel, $type, $assoc, $assocData, $queryData, true, $modelData, $queryData['recursive'] - 1, $stack);
				}
			}
		}

		return $modelData;
	}

	public function create(Model $Model, $fields = null, $values = null) {
		$data = array(
			$this->Node->alias => array_combine($fields, $values)
		);
		$data[$this->Node->alias]['type'] = Inflector::singularize($Model->table);

		if (!$this->Node->create()) {
			return false;
		}

		$data = $this->__moveCustomToCustomFields($Model, $data);

		$result = $this->Node->saveAll($data, array('deep' => true));
		return $result;
	}


	public function update(Model $Model, $fields = null, $values = null, $conditions = null) {
		if ($values !== null) {
			$data = array(
				$this->Node->alias => array_combine($fields, $values),
			);

			$data[$this->Node->alias ]['type'] = Inflector::singularize($Model->table);

			$data = $this->__moveCustomToCustomFields($Model, $data);

			$result = $this->Node->saveAll($data, array('deep' => true));

			return $result;
		} else {
			foreach ($fields as $field => $value) {
				unset($fields[$field]);
				list ($field, $model) = array_reverse(explode('.', $field));

				if ($model === $Model->alias) {
					$model = $this->Node->alias;
				}

				$value = str_replace($Model->alias . '.', $this->Node->alias . '.', $value);

				$fields[$model . '.' . $field] = $value;
			}

			$result = $this->Node->updateAll($fields, array(
				$this->Node->alias . '.type' => Inflector::singularize($Model->table)
			));

			return $result;
		}
	}

	public function delete(Model $Model, $conditions = null) {
		$deleteConditions = $this->__rebuildConditions($Model ,$conditions);
		$deleteConditions[$this->Node->alias . '.type'] = Inflector::singularize($Model->table);

		$result = $this->Node->deleteAll($deleteConditions);
		return $result;
	}

	public function name($data) {
		return $data;
	}

	public function queryAssociation(Model $model, &$linkModel, $type, $association, $assocData, &$queryData, $external = false, &$resultSet, $recursive, $stack) {
		if (!$association) {
			return;
		}
		if (!$resultSet) {
			return;
		}

		$assocData = array_merge(array('conditions' => null, 'fields' => null, 'order' => null), $assocData);
		if (isset($queryData['fields'])) {
			$assocData['fields'] = array_merge((array)$queryData['fields'], (array)$assocData['fields']);
		}
		foreach ($resultSet as $id => $result) {
			if (!array_key_exists($model->alias, $result)) {
				continue;
			}
			if ($type === 'belongsTo' && array_key_exists($assocData['foreignKey'], $result[$model->alias])) {
//				debug( array_merge((array)$assocData['conditions'], array($model->{$association}->primaryKey => $result[$model->alias][$assocData['foreignKey']])));exit();
				$find = $model->{$association}->find('first', array(
					'conditions' => array_merge((array)$assocData['conditions'], array($association . '.' . $model->{$association}->primaryKey => $result[$model->alias][$assocData['foreignKey']])),
					'fields' => $assocData['fields'],
					'order' => $assocData['order'],
					'recursive' => $recursive
				));
			} elseif (in_array($type, array('hasOne', 'hasMany')) && array_key_exists($model->primaryKey, $result[$model->alias])) {
				if ($type === 'hasOne') {
					$find = $model->{$association}->find('first', array(
						'conditions' => array_merge((array)$assocData['conditions'], array($association . '.' . $assocData['foreignKey'] => $result[$model->alias][$model->primaryKey])),
						'fields' => $assocData['fields'],
						'order' => $assocData['order'],
						'recursive' => $recursive
					));
				} else {
					$find = $model->{$association}->find('all', array(
						'conditions' => array_merge((array)$assocData['conditions'], array($association . '.' . $assocData['foreignKey'] => $result[$model->alias][$model->primaryKey])),
						'fields' => $assocData['fields'],
						'order' => $assocData['order'],
						'recursive' => $recursive
					));
					$find = array(
						$association => (array)Set::extract('{n}.' . $association, $find)
					);
				}
			} elseif ($type === 'hasAndBelongsToMany' && array_key_exists($model->primaryKey, $result[$model->alias])) {
				$find = array();
				$hABTMModel = ClassRegistry::init($assocData['with']);
				$ids = $hABTMModel->find('all', array(
					'fields' => array(
						$assocData['with'] . '.' . $assocData['associationForeignKey']
					),
					'conditions' => array(
						$assocData['with'] . '.' . $assocData['foreignKey'] => $result[$model->alias][$model->primaryKey]
					)
				));
				if ($ids) {
					$ids = Set::extract('{n}.' . $assocData['with'] . '.' . $assocData['associationForeignKey'], $ids);
					$find = $model->{$association}->find('all', array(
						'conditions' => array_merge((array)$assocData['conditions'], array($association . '.' . $linkModel->primaryKey => $ids)),
						'fields' => $assocData['fields'],
						'order' => $assocData['order'],
						'recursive' => $recursive
					));
					$find = array(
						$association => (array)Set::extract('{n}.' . $association, $find)
					);
				}
			}
			if (empty($find)) {
				$find = array($association => array());
			}
			$resultSet[$id] = array_merge($find, $resultSet[$id]);
		}
	}


	private function __moveCustomToCustomFields(Model $Model, $data) {
		$schema = $this->describe($Model);

		foreach ($data as $model => $values) {
			if ($model !== $this->Node->alias) {
				continue;
			}

			foreach ($values as $key => $value) {
				if (!isset($schema[$key]['custom'])) {
					continue;
				}

				if (isset($data['Node']['id'])) {
					$metaFieldData = $this->Node->Meta->find('list', array(
						'conditions' => array(
							$this->Node->Meta->alias . '.model'       => $this->Node->alias,
							$this->Node->Meta->alias . '.foreign_key' => $data['Node']['id'],
							$this->Node->Meta->alias . '.key'         => $key,
						)
					));
				} else {
					$metaFieldData = array();
				}


				$metaData = array(
					'model'       => $this->Node->alias,
//					'foreign_key' => $data['Node']['id'],
					'key'         => $key,
					'value'       => $value,
					'weight'      => null,
				);
				if (count($metaFieldData) > 0) {
					$metaData['id'] = array_shift($metaFieldData);
				}
				$data['Meta'][] = $metaData;
			}
		}

		return $data;
	}

	private function __rebuildConditions(Model $Model, $conditions) {
		if (!is_array($conditions)) {
			return array($conditions);
		}

		$queryConditions = array();
		foreach ($conditions as $field => $condition) {
			list ($field, $model) = array_reverse(explode('.', 'no-model.' . $field));

			if (strstr($field, ' ')) {
				$fieldParts = explode(' ', $field);
				if ($Model->hasField($fieldParts[0])) {
					$queryConditions[$this->Node->alias . '.' . $fieldParts[0] . ' ' . $fieldParts[1]] = $condition;
				}
			} else {
				if ($Model->hasField($field)) {
					$queryConditions[$this->Node->alias . '.' . $field] = $condition;
				} else {
					if (is_array($condition)) {
						$queryConditions[$field] = $this->__rebuildConditions($Model, $condition);
					} else {
						$queryConditions[$field] = $condition;
					}
				}
			}
		}

		return $queryConditions;
	}

}