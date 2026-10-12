<?php
/**
 * public/index.php  -  the FRONT CONTROLLER.
 * Every browser request enters the application here, nowhere else.
 * (Only the public/ folder is meant to be reachable from the web.)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

(new Router())->dispatch();
