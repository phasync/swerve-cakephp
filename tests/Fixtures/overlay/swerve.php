<?php

require __DIR__ . '/vendor/autoload.php';

// With SWERVE_TEST_TERMINATE, a Server.terminate listener that does slow work (waits 0.1 s) and changes Configure
if (getenv('SWERVE_TEST_TERMINATE')) {
    Cake\Event\EventManager::instance()->on('Server.terminate', function () {
        extension_loaded('phasync') ? usleep(100_000) : phasync::sleep(0.1);
        Cake\Core\Configure::write('SwerveTest.v', 'terminate');
    });
}

return new Swerve\CakePHP\Handler(__DIR__);
