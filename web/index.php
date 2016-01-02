<?php

require_once __DIR__.'/../vendor/autoload.php';

// for $app->before
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

// for $app->post
use Symfony\Component\HttpFoundation\Response;


use CnTech\JsonSql\SecureField;
use CnTech\JsonSql\Filter;
use CnTech\JsonSql\SubRecords;


$app = new Silex\Application();

$app['debug'] = true;

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
  'db.options' => array(
    'driver' => 'pdo_sqlite',
    'path' => __DIR__.'/db/firmcheck.db',
  ),
));


// READ

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
  $filterer = new Filter($qb);
  $filterer->setAllowedColumns($columns);
  $where = call_user_func_array(
    array($qb->expr(), 'andX'),
    $filterer->apply($filter)
  );
  if(!empty($where)) {
    $qb->where($where);
    // now, handle the count query:
    $count_filterer = new Filter($count_qb, $count_outer_qb);
    $count_filterer->setAllowedColumns($columns);
    $count_qb->where(
      call_user_func_array(
        array($count_qb->expr(), 'andX'),
        $count_filterer->apply($filter)
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
  $includer = new SubRecords($app['db']);
  foreach($includes as $include) {
    $includer->includeSubRecords($firms, 'firm_id', $include, $ids);
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

