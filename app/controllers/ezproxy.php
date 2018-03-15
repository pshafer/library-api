<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$ezproxy = $app['controllers_factory'];

$ezproxy->get('/test', function(Application $app) {
    return new Response('Hello World', 200);
});

$ezproxy->get('/info', function(Application $app) {
    return $app->json($_SERVER);
});

$ezproxy->options("{anything}", function (Application $app, Request $request) {
    return $app->json($app['config']['ezproxy.report']);
})->assert('anything', '.*');


$ezproxy->post('/sendreport', function(Application $app, Request $request) {

    $data = $request->request->all();
    $message = \Swift_message::newInstance()
        ->setSubject($app['config']['ezproxy.report']['subject'])
        ->setFrom($data['email'])
        ->setTo($app['config']['ezproxy.report']['to'])
        ->setBody($app['twig']->render('ezproxy/outmessage.html.twig',
            $data
        ), 'text/html');

    if($app['mailer']->send($message)){
        return $app->json(array('status' => '201', 'message' => 'success'), 201);
    }

    return $app->json(array('status' => '500', 'message' => 'error occured'), 500);
});

$ezproxy->after(function(Request $request, Response $response, Application $app) {
    $origin = trim($request->headers->get('Origin'));
    if(in_array($origin, $app['config']['ezproxy.report']['origins'])){
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Headers', 'Cache-Control, Content-Language, Content-Type, Expires, Last-Modified, Pragma');
    }

});

return $ezproxy;