# Third-party notices

This repository contains an adapted copy of [Frug/AJAX-Chat](https://github.com/Frug/AJAX-Chat). Its modified MIT notice is retained at [chat-engine/license.txt](chat-engine/license.txt). Bundled icons and emoticons have separate Creative Commons Attribution-ShareAlike 2.5 notices at [chat-engine/img/license.txt](chat-engine/img/license.txt). Preserve those notices when distributing the files.

PHP packages are pinned in [composer.lock](composer.lock): `delight-im/auth` and its dependencies are MIT licensed; PHPMailer is LGPL-2.1-only. Composer installs the upstream license files with those packages. [scripts/patch-auth.php](scripts/patch-auth.php) modifies the authentication package after installation; preserve its upstream license and copyright notices.

The AOV channel button SVGs and synthesized modem/chat tones are generated from source in this repository. Three spoken clips were generated with ElevenLabs and have separate terms described in [assets/sounds/README.md](assets/sounds/README.md). The official AOV identity assets have separate terms in [BRANDING.md](BRANDING.md).
