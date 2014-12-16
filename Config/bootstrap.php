<?php

App::uses('ConnectionManager', 'Model');

ConnectionManager::create('nodes', array(
	'datasource' => 'NodesDatasource.CroogoNodesSource'
));
