<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;


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
    $message = \Swift_Message::newInstance()
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
})->before(function(Request $request, Application $app) {

    $constraints = new Assert\Collection(array(
        'name' => new Assert\NotBlank(array('message' => 'Name is required')),
        'email' => array(
            new Assert\Email(array('message' => 'A valid email address is required')),
            new Assert\Regex(array(
                'pattern' => '/^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((students\.)?rowan\.edu|cooperhealth\.edu)$/i',
                'message' => 'A valid @rowan.edu, @students.rowan.edu, or @cooperhealth.edu email is required'
                )
            )),
        'destinationUrl' => array(
            new Assert\NotBlank(array('message' => 'Requested URL is Required')),
            new Assert\Url(array('message' => 'Destination URL must be a valid URL'))
            ),
        'destinationHost' => new Assert\NotBlank(array('message' => 'Requested Host is Required')),
        'userAgent' => new Assert\NotBlank(array('message' => 'User Agent is Required')),
        'message' => new Assert\Optional(),
        'referrer' => new Assert\Optional()
    ));

    $data = $request->request->all();
    $errors = $app['validator']->validate($data, $constraints);

    if(count($errors) > 0) {
        $payload = array();
        foreach($errors as $error) {
            $field = substr($error->getPropertyPath(), 1, -1);
            if(!array_key_exists($field, $payload)){
                $payload[$field] = array();
            }
            $payload[$field][] = $error->getMessage();
        }

        $data = new StdClass();
        $data->status = 400;
        $data->statusMessage = 'You request is invalid and cannot be completed';
        $data->errors = $payload;

        return $app->json($data, 400);
    }
});

$ezproxy->after(function(Request $request, Response $response, Application $app) {
    $origin = trim($request->headers->get('Origin'));
    if(in_array($origin, $app['config']['ezproxy.report']['origins'])){
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Headers', 'Cache-Control, Content-Language, Content-Type, Expires, Last-Modified, Pragma');
    }

});

return $ezproxy;