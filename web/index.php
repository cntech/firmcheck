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

function filter($qb, $columns, $filter) {
  $not_columns = array_map(function($item) { return '!'.$item; }, $columns);
  $i = 1;
  foreach($filter as $key => $value) {
    $index = array_search($key, $columns);
    if($index !== FALSE) {
      // use eq comparison
      $secure_key = $columns[$index];
      $qb->andWhere($secure_key.'=:value'.$i)
         ->setParameter('value'.$i, $value);
      //echo 'value eq'.$i.' '.$secure_key."\n";
      $i = $i + 1;
    } else {
      $index = array_search($key, $not_columns);
      if($index !== FALSE) {
        // use neq comparison
        $secure_key = $columns[$index];
        $qb->andWhere($secure_key.'<>:value'.$i)
           ->setParameter('value'.$i, $value);
        //echo 'value neq'.$i.' '.$secure_key."\n";
        $i = $i + 1;
      } else {
        return FALSE;
      }
    }
  }
  return TRUE;
}

$app->get('/firms', function(Request $request) use ($app) {
  $qb = $app['db']->createQueryBuilder();
  $count_qb = $app['db']->createQueryBuilder();
  
  $filter = json_decode($request->query->get('filter'));
  $offset = ((int)$request->query->get('offset')) ?: 0;
  $limit = ((int)$request->query->get('limit')) ?: FALSE;
  
  $qb->select('*')->from('firms');
  $count_qb->select('count(*)')->from('firms');
  
  // filter
  $columns = array('id', 'area', 'name', 'firmenAbcUrl', 'homepages');
  $result = filter($qb, $columns, $filter);
  if($result === FALSE) {
    // filter contains invalid column names
    return $app->json(json_decode('{}'));
  }
  filter($count_qb, $columns, $filter);
  
  // offset and limit
  $qb->setFirstResult($offset);
  if($limit) {
    $qb->setMaxResults($limit);
  }
  
  // execute the count query
  $query = $count_qb->execute();
  $count = (int)($query->fetch()['count(*)']);
  
  // execute the records query
  $query = $qb->execute();
  $firms = $query->fetchAll();
  
  // return the results as JSON object
  return $app->json(array(
    'total_count' => $count,
    'data' => $firms
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

