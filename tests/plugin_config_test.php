<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = include $root.'/Config/config.php';
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$controller = (string) file_get_contents($root.'/Controller/MjmlApiController.php');

$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect('2.0.0' === ($config['version'] ?? null), 'config version must be 2.0.0');
$expect(!isset($config['services']), 'legacy config.php services must not be registered');
$expect(is_file($root.'/Config/services.php'), 'Config/services.php is required for Mautic 7 autowiring');
$expect(is_file($root.'/DependencyInjection/MauticMjmlApiExtension.php'), 'bundle extension must load services.php');

$routes = $config['routes']['api'] ?? [];
$expect(isset($routes['mautic_api_mjml_emails_new']), 'create route is missing');
$expect(isset($routes['mautic_api_mjml_emails_edit']), 'edit route is missing');

$expect(
    'MauticPlugin\\MauticMjmlApiBundle\\Controller\\MjmlApiController::createEmailAction' === ($routes['mautic_api_mjml_emails_new']['controller'] ?? null),
    'create route must use the FQCN controller'
);
$expect(
    'MauticPlugin\\MauticMjmlApiBundle\\Controller\\MjmlApiController::editEmailAction' === ($routes['mautic_api_mjml_emails_edit']['controller'] ?? null),
    'edit route must use the FQCN controller'
);
$expect('POST' === ($routes['mautic_api_mjml_emails_new']['method'] ?? null), 'create route must be POST');
$expect('PATCH' === ($routes['mautic_api_mjml_emails_edit']['method'] ?? null), 'edit route must be PATCH');
$expect('/emails/mjml/new' === ($routes['mautic_api_mjml_emails_new']['path'] ?? null), 'create path changed');
$expect('/emails/mjml/{id}/edit' === ($routes['mautic_api_mjml_emails_edit']['path'] ?? null), 'edit path changed');

$expect('>=8.2' === ($composer['require']['php'] ?? null), 'composer must require PHP 8.2');
$expect('^7.0' === ($composer['require']['mautic/core-lib'] ?? null), 'composer must require mautic/core-lib 7');
$expect(!str_contains($controller, 'getDoctrine('), 'controller must not call getDoctrine()');
$expect(str_contains($controller, '$this->doctrine->getManager()'), 'controller must use the injected doctrine registry');
$expect(str_contains($controller, 'parent::__construct('), 'controller must initialize CommonApiController');
$expect(str_contains($controller, 'setSerializationContext('), 'responses must set the Mautic serializer context');
$expect(str_contains($controller, 'Europe/Ljubljana'), 'publish dates must stay in Europe/Ljubljana');

if ([] !== $failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}

echo "plugin config checks passed\n";
