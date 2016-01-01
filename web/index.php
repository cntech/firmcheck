<?php

require_once __DIR__.'/../vendor/autoload.php';

// for $app->before
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

// for $app->post
use Symfony\Component\HttpFoundation\Response;

$app = new Silex\Application();

$app['debug'] = true;

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
  'db.options' => array(
    'driver' => 'pdo_sqlite',
    'path' => __DIR__.'/db/firmcheck.db',
  ),
));


// READ

function secure_field($columns, $field) {
  $index = array_search($field, $columns);
  if($index !== FALSE) {
    return $columns[$index];
  }
  return FALSE;
}

function field_filter($qb, $param_qb, $columns, $field, $filter) {
  $secfield = secure_field($columns, $field);
  if($secfield !== FALSE) {
    if(is_array($filter)) {
      if(array_key_exists('$in', $filter)) {
        $in_query = $secfield.' IN (';
        $in_query.= join(', ', array_map(function($item) use ($param_qb) {
          return $param_qb->createNamedParameter($item);
        }, $filter['$in']));
        $in_query.= ')';
        return $in_query;
      }
      if(array_key_exists('$like', $filter)) {
        $secvalue = $param_qb->createNamedParameter($filter['$like']);
        return $qb->expr()->like($secfield, $secvalue);
      }
      if(array_key_exists('$not', $filter)) {
        $secvalue = $param_qb->createNamedParameter($filter['$not']);
        return $qb->expr()->neq($secfield, $secvalue);
      }
      if(array_key_exists('$lt', $filter)) {
        $secvalue = $param_qb->createNamedParameter($filter['$lt']);
        return $qb->expr()->lt($secfield, $secvalue);
      }
      if(array_key_exists('$lte', $filter)) {
        $secvalue = $param_qb->createNamedParameter($filter['$lte']);
        return $qb->expr()->lte($secfield, $secvalue);
      }
      if(array_key_exists('$gt', $filter)) {
        $secvalue = $param_qb->createNamedParameter($filter['$gt']);
        return $qb->expr()->gt($secfield, $secvalue);
      }
      if(array_key_exists('$gte', $filter)) {
        $secvalue = $param_qb->createNamedParameter($filter['$gte']);
        return $qb->expr()->gte($secfield, $secvalue);
      }
    } else {
      $secvalue = $param_qb->createNamedParameter($filter);
      return $qb->expr()->eq($secfield, $secvalue);
    }
  }
  return '(0=1)';
}

function filter($qb, $param_qb, $columns, $filter) {
  $result = array();
  foreach($filter as $key => $value) {
    if($key[0] == '$') {
      $sub_result = filter($qb, $param_qb, $columns, $value);
      if($filter['$and']) {
        array_push($result, call_user_func_array(array($qb->expr(), 'andX'), $sub_result));
      }
      if($filter['$or']) {
        array_push($result, call_user_func_array(array($qb->expr(), 'orX'), $sub_result));
      }
    } else {
      array_push($result, field_filter($qb, $param_qb, $columns, $key, $value));
    }
  }
  if(empty($result)) {
    return '(1=1)';
  }
  return $result;
}

function include_subrecords($db,
    &$parent_records, $parent_id_field,
    $child_table, $parent_ids) {
  
  // query sub-records
  $qb = $db->createQueryBuilder();
  $qb->select('*')->from($child_table);
  $parent_id_placeholders = array_map(function($item) use ($qb) {
    return $qb->createPositionalParameter($item);
  }, $parent_ids);
  $joined_parent_id_placeholders = join(', ', $parent_id_placeholders);
  $qb->where('"'.$parent_id_field.'" IN ('.$joined_parent_id_placeholders.')');
  $query = $qb->execute();
  $child_records = $query->fetchAll();
  
  // attach sub-records to parent records
  $keyed_parent_records = array();
  foreach($parent_records as &$parent_record) {
    $parent_record[$child_table] = array();
    $keyed_parent_records[$parent_record['id']] = &$parent_record;
  }
  foreach($child_records as $child_record) {
    array_push(
      $keyed_parent_records[$child_record[$parent_id_field]][$child_table],
      $child_record
    );
  }
  
}

$app->get('/firms', function(Request $request) use ($app) {
  $qb = $app['db']->createQueryBuilder();
  $count_qb = $app['db']->createQueryBuilder();
  $count_outer_qb = $app['db']->createQueryBuilder();
  
  $filter = json_decode($request->query->get('filter'), TRUE) ?: array();
  $includes = json_decode($request->query->get('includes'), TRUE) ?: array();
  $offset = ((int)$request->query->get('offset')) ?: 0;
  $limit = ((int)$request->query->get('limit')) ?: 500; //FALSE;
  // limit is set to less than 999 to not exceed sqlite3's
  // maximum number of host parameters in a sub-record query
  
  $firm_fields = array('f.id', 'f.area', 'f.name', 'f.firmenAbcUrl', 'f.homepages');
  $rating_fields = array('GROUP_CONCAT(r.name) as rating_names', 'GROUP_CONCAT(r.rating) as rating_values');

  $fields = array_merge($firm_fields, $rating_fields);
  
  $qb->select($fields)->from('firms', 'f');
  $count_qb->select('COUNT(f.id)')->from('firms', 'f');
  
  // join and group
  $qb->      leftJoin('f', 'ratings', 'r', 'r.firm_id = f.id');
  $count_qb->leftJoin('f', 'ratings', 'r', 'r.firm_id = f.id');
  $qb->groupBy($firm_fields);
  $count_qb->groupBy($firm_fields);
  
  // filter
  $columns = array('id', 'area', 'name', 'firmenAbcUrl', 'homepages');
  $columns = array_merge(
    $columns,
    array_map(function($item) { return 'f.'.$item; }, $columns),
    array('r.name', 'r.rating')
  );
  $where = call_user_func_array(array($qb->expr(), 'andX'), filter($qb, $qb, $columns, $filter));
  if(!empty($where)) {
    $qb->where($where);
    $count_qb->where(
      call_user_func_array(
        array($count_qb->expr(), 'andX'),
        filter($count_qb, $count_outer_qb, $columns, $filter)
      )
    );
  }
  
  // offset and limit
  $qb->setFirstResult($offset);
  if($limit) {
    $qb->setMaxResults($limit);
  }
  
  // order
  $qb->orderBy('f.id', 'ASC');
  
  $count_outer_qb->select('COUNT(*)')->from('('.$count_qb->getSQL().')');
  
  // execute the count query
  $query = $count_outer_qb->execute();
  $count = (int)($query->fetch()['COUNT(*)']);
  
  // execute the records query
  $query = $qb->execute();
  $firms = $query->fetchAll();
  
  // include sub-records
  $ids = array_map(function($firm) {
    return $firm['id'];
  }, $firms);
  foreach($includes as $include) {
    include_subrecords($app['db'], $firms, 'firm_id', $include, $ids);
  }
  
  // return the results as JSON object
  return $app->json(array(
    'total_count' => $count,
    'data' => $firms,
    'query' => $qb->getSQL(),
    'count_query' => $count_outer_qb->getSQL(),
  ));
  
});

$app->get('/areas', function () use ($app) {
  $sql = "SELECT area FROM firms GROUP BY area";
  $areas = $app['db']->fetchAll($sql);
  return $app->json($areas);
});

$app->get('/firms/{firm_id}/ratings', function ($firm_id) use ($app) {
  $sql = "SELECT * FROM ratings WHERE firm_id = ?";
  $ratings = $app['db']->fetchAll($sql, array($firm_id));
  return $app->json($ratings);
});

$app->get('/ratings', function () use ($app) {
  $sql = "SELECT * FROM ratings";
  $ratings = $app['db']->fetchAll($sql);
  return $app->json($ratings);
});

$app->get('/ratings/{id}', function ($id) use ($app) {
  $sql = "SELECT * FROM ratings WHERE id = ?";
  $rating = $app['db']->fetchAssoc($sql, array((int)$id));
  return $app->json($rating? array($rating,) : array());
})->assert('id', '\d+');


// WRITE

$app->before(function (Request $request) {
  if(0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
    $data = json_decode($request->getContent(), true);
    $request->request->replace(is_array($data)? $data : array());
  }
});

$app->post('/firms/{firm_id}/ratings', function ($firm_id, Request $request) use ($app) {
  $post = array(
    'firm_id' => $firm_id,
    'name' => $request->request->get('name'),
    'rating' => $request->request->get('rating'),
  );
  $app['db']->insert('ratings', $post);
  $post['id'] = $app['db']->lastInsertId();
  return $app->json($post);
});

$app->put('/ratings/{id}', function ($id, Request $request) use ($app) {
  $put = array();
  $firm_id = $request->request->get('firm_id');
  if($firm_id) { $put['firm_id'] = $firm_id; }
  $name = $request->request->get('name');
  if($name) { $put['name'] = $name; }
  $rating = $request->request->get('rating');
  if($rating) { $put['rating'] = $rating; }
  $result = $app['db']->update('ratings', $put, array('id' => $id));
  return $app->json(array('n_updated' => $result));
});

$app->delete('/firms/{firm_id}/ratings', function ($firm_id, Request $request) use ($app) {
  $id = $request->query->get('id');
  $result = $app['db']->delete('ratings', array('firm_id' => $firm_id, 'id' => $id));
  return $app->json(array('n_deleted' => $result));
});


$app->run();

