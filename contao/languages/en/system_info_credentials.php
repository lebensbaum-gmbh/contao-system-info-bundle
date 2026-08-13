<?php

$GLOBALS['TL_LANG']['system_info_credentials'] = [
    'headline' => 'System info credentials',
    'intro' => 'These credentials securely connect this Contao installation to the central domain manager.',
    'installationId' => 'Installation ID',
    'secret' => 'Secret',
    'secretVisibleHelp' => 'Copy the secret now and hide it afterwards. Once hidden, this secret will not be shown in clear text again.',
    'secretHiddenHelp' => 'The secret is stored encrypted and is not shown in clear text again for security reasons. If it is no longer available, a new one can be generated.',
    'secretChanged' => 'Secret last changed',
    'endpoint' => 'System info endpoint',
    'copy' => 'Copy',
    'copied' => 'Copied',
    'rotate' => 'Generate new secret',
    'rotateConfirm' => 'Generate a new secret? The existing secret becomes invalid immediately and must also be replaced in the domain manager.',
    'rotateHelp' => 'The installation ID remains unchanged.',
    'hide' => 'Hide secret',
    'hideConfirm' => 'Hide the secret now? Make sure you copied it and stored it in the domain manager first.',
    'error' => 'The credentials could not be loaded.',
];
