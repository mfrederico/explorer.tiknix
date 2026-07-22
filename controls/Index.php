<?php
/**
 * Index — sidecar root. Sends visitors to /explore (which requires the SSO
 * session and otherwise bounces them to core's launch link).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Index extends Control {
    public function index($params = []) {
        Flight::redirect('/explore');
    }
}
