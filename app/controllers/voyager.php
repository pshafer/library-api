<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$voyager = $app['controllers_factory'];

$voyager->get('summonrta', function( Application $app, Request $request) {

    $bibids = $request->get('bibid');
    if($bibids){
        if(!is_array($bibids)){
            $bibids = [ $bibids ];
        }

        $result = $app['voyager.service']->getSummonStatuses($bibids);

        return $app->json($result);
    }

    return $app->json([ 'status' => 404, 'message' => 'bibid is required']);

});

return $voyager;