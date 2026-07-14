# KZChangeRequest Extension

The `KZChangeRequest` extension for MediaWiki allows users to submit change requests for articles. It integrates with Cloudflare Turnstile to prevent spam and provides a fallback email option if the CAPTCHA fails to load.

## Changelog

### 0.0.3

- Modernized PHP autoloading to use the extension namespace for hook and API classes.
- Moved the hook handler to `includes/Hooks.php` and kept the public helper available through a legacy compatibility alias.
- Updated the development toolchain configuration to current MediaWiki extension defaults.

## Installation

1. Download and place the `KZChangeRequest` directory in your `extensions/` folder.
2. Add the following code at the bottom of your `LocalSettings.php`:

    ```php
    wfLoadExtension( 'KZChangeRequest' );
    ```

3. Configure the extension by setting the required configuration options in `LocalSettings.php`.

## Configuration Options

| Option                              | Description                                                                              | Default Value  |
|-------------------------------------|------------------------------------------------------------------------------------------|----------------|
| `KZChangeRequestTurnstileSiteKey`   | The site key for Cloudflare Turnstile. Identified per-service by `cData: kzchangerequest`. | (empty)        |
| `KZChangeRequestTurnstileSecretKey` | The secret key for Cloudflare Turnstile.                                                 | (empty)        |
| `KZChangeRequestJiraServiceDeskApi` | Configuration for Jira Service Desk API.                                                 | (empty object) |
| `KZChangeRequestFallbackEmail`      | Email address to show when the CAPTCHA fails to load. If empty, no fallback will be shown. | (empty)        |

### Jira Service Desk API Configuration

The `KZChangeRequestJiraServiceDeskApi` option is an object with the following properties:

| Property          | Description                     | Default Value |
|-------------------|---------------------------------|---------------|
| `user`            | Jira Service Desk API user.     | (empty)       |
| `password`        | Jira Service Desk API password. | (empty)       |
| `project`         | Jira project key.               | (empty)       |
| `server`          | Jira server URL.                | (empty)       |
| `serviceDeskId`   | Service Desk ID.                | (empty)       |
| `requestTypeId`   | Request Type ID.                | (empty)       |
| `shortLinkFormat` | Format for short links.         | (empty)       |

## Usage

To use the extension, the change request button must first be added. You can add any element with the class
`changerequest-btn`, or use the extension's own `\KZChangeRequest::createChangeRequestButton( $articleId )` function.

Then, navigate to a page where the change request form is enabled. Fill out the form and submit your request.
If the CAPTCHA fails to load, a fallback email option will be provided.

## License

This extension is licensed under the GPL-2.0-or-later license. For more information, see the `LICENSE` file.
