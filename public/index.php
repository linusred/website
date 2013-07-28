<?php
use Destiny\Common\Service\Fantasy\TeamService;

use Destiny\Common\Application;
use Destiny\Common\UserRole;
use Destiny\Common\AppEvent;
use Destiny\Common\Service\UserService;
use Destiny\Common\Service\AuthenticationService;
use Destiny\Common\AppException;
use Destiny\Common\Utils\Http;
use Destiny\Common\SessionCredentials;
use Destiny\Common\SessionCookie;
use Destiny\Common\SessionInstance;
use Destiny\Common\Session;
use Destiny\Common\Config;
use Destiny\Common\Service\ChatIntegrationService;
use Destiny\Common\Router;
use Destiny\Common\Routing\AnnotationDirectoryLoader;
use Destiny\Common\Service\RememberMeService;
use Doctrine\Common\Annotations\FileCacheReader;
use Doctrine\Common\Annotations\AnnotationReader;

ini_set ( 'session.gc_maxlifetime', 5 * 60 * 60 );

$context = new stdClass ();
$context->log = 'web';
require __DIR__ . '/../lib/boot.php';
$app = Application::instance ();

// Setup user session
$session = new SessionInstance ();
$session->setSessionCookie ( new SessionCookie ( Config::$a ['cookie'] ) );
$session->setCredentials ( new SessionCredentials () );
$app->setSession ( $session );

// Start the session if a valid session cookie is found
Session::start ( Session::START_IFCOOKIE );

// Startup the remember me and auth service
AuthenticationService::instance ()->init ();
RememberMeService::instance ()->init ();

// Read all the @Route annotations from the classes within [lib]Destiny/Web/Action
// Would be nice if a RedisFileCacheReader existed, or could be custom built
$reader = new FileCacheReader ( new AnnotationReader (), realpath ( Config::$a ['cache'] ['path'] ) . '/annotation/' );
$app->setAnnotationReader ( $reader );
$app->setRouter ( new Router ( AnnotationDirectoryLoader::load ( $reader, _LIBDIR . '/', 'Destiny/Action/' ) ) );

// @TODO find a better place for this
// If this user has no team, create a new one
$teamId = Session::get ( 'teamId' );
if (Session::hasRole ( UserRole::USER ) && empty ( $teamId )) {
	$teamService = TeamService::instance ();
	$team = $teamService->getTeamByUserId ( Session::getCredentials ()->getUserId () );
	if (empty ( $team )) {
		$team = array ();
		$team ['teamId'] = $teamService->addTeam ( Session::getCredentials ()->getUserId (), Config::$a ['fantasy'] ['team'] ['startCredit'], Config::$a ['fantasy'] ['team'] ['startTransfers'] );
	}
	Session::set ( 'teamId', $team ['teamId'] );
}

// Attempts to find a route and execute the action
$app->executeRequest ( (isset ( $_SERVER ['REQUEST_URI'] )) ? $_SERVER ['REQUEST_URI'] : '', $_SERVER ['REQUEST_METHOD'] );
?>