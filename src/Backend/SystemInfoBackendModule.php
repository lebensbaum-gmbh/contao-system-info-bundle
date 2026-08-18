<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Backend;

use Contao\BackendTemplate;
use Contao\Controller;
use Contao\Input;
use Contao\System;
use Lebensbaum\ContaoSystemInfoBundle\Security\CredentialStore;
use Symfony\Component\Security\Csrf\CsrfToken;

final class SystemInfoBackendModule
{
    public function generate(): string
    {
        System::loadLanguageFile('modules');
        System::loadLanguageFile('system_info_credentials');

        $container = System::getContainer();
        /** @var CredentialStore $credentialStore */
        $credentialStore = $container->get(CredentialStore::class);
        $request = $container->get('request_stack')->getCurrentRequest();

        $template = new BackendTemplate('be_system_info_credentials');
        $template->headline = $GLOBALS['TL_LANG']['system_info_credentials']['headline'] ?? 'System-Info-Zugangsdaten';
        $template->intro = $GLOBALS['TL_LANG']['system_info_credentials']['intro'] ?? '';
        $template->error = null;
        $template->systemId = '';
        $template->plainSecret = null;
        $template->secretChangedAt = 0;
        $template->endpointUrl = null !== $request
            ? rtrim($request->getSchemeAndHttpHost(), '/').'/_domainverwaltung/systeminfo'
            : '/_domainverwaltung/systeminfo';

        $csrfTokenManager = $container->get('contao.csrf.token_manager');
        $csrfTokenName = (string) $container->getParameter('contao.csrf_token_name');
        $template->requestToken = $csrfTokenManager->getToken($csrfTokenName)->getValue();

        // Handle state-changing POST actions before rendering. All successful
        // actions use POST/Redirect/GET, so browser refreshes never repeat them.
        if (
            null !== $request
            && $request->isMethod('POST')
            && 'system_info_credentials' === (string) Input::post('FORM_SUBMIT')
        ) {
            try {
                $submittedToken = (string) Input::post('REQUEST_TOKEN');
                $token = new CsrfToken($csrfTokenName, $submittedToken);

                if (!$csrfTokenManager->isTokenValid($token)) {
                    throw new \RuntimeException('Ungültiger Request-Token. Bitte die Seite neu laden.');
                }

                $action = (string) Input::post('action');

                if ('show_secret' === $action) {
                    $credentialStore->revealSecretOnce();
                } elseif ('rotate_secret' === $action) {
                    $credentialStore->rotateSecret();
                } elseif ('hide_secret' === $action) {
                    $credentialStore->hidePendingReveal();
                } else {
                    throw new \RuntimeException('Unbekannte System-Info-Aktion.');
                }
            } catch (\Throwable $e) {
                $template->error = $e->getMessage();
            }

            if (null === $template->error) {
                Controller::redirect($request->getUri());
            }
        }

        if (null === $template->error) {
            try {
                $metadata = $credentialStore->getMetadata();
                $template->systemId = $metadata['system_id'];
                $template->secretChangedAt = $metadata['secret_changed_at'];
                $template->plainSecret = $credentialStore->getPendingReveal();
            } catch (\Throwable $e) {
                $template->error = $e->getMessage();
            }
        }

        return $template->parse();
    }
}
