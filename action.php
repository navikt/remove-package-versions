<?php declare(strict_types=1);
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

require 'vendor/autoload.php';

/**
 * Fail the script with a message
 *
 * @param string $message
 * @return void
 */
function fail(string $message) : void {
    halt($message, 1);
}

/**
 * Halt the script with a message
 *
 * @param string $message
 * @param int $code
 * @return void
 */
function halt(string $message, int $code = 0) : void {
    echo trim($message) . PHP_EOL;
    exit($code);
}

/**
 * Output a debug message
 *
 * @param string $message
 * @return void
 */
function debug(string $message) : void {
    echo trim($message) . PHP_EOL;
}

/**
 * Check if a version is semantic or not
 *
 * @see https://semver.org/#is-there-a-suggested-regular-expression-regex-to-check-a-semver-string
 * @param string $version
 * @return bool
 */
function isSemanticVersion(string $version) : bool {
    return 0 < preg_match('/^(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<prerelease>(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+(?P<buildmetadata>[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/', $version);
}

$token             = (string) getenv('GITHUB_TOKEN');
$keepVersions      = (int) getenv('INPUT_KEEP_VERSIONS') ?: 5;
$removeSemver      = 'true' === getenv('INPUT_REMOVE_SEMVER');
$repoNameWithOwner = (string) getenv('GITHUB_REPOSITORY');
$clientId          = 'navikt/remove-package-versions';

if (empty($token)) {
    fail('Missing GITHUB_TOKEN');
} else if (empty($repoNameWithOwner)) {
    fail('Missing GITHUB_REPOSITORY');
} else if (false === strpos($repoNameWithOwner, '/')) {
    fail('Invalid GITHUB_REPOSITORY value');
}

[$owner, $repositoryName] = explode('/', $repoNameWithOwner, 2);

$client = new Client([
    'base_uri' => 'https://api.github.com/',
    'headers' => [
        'Accept'        => 'application/vnd.github.packages-preview+json', // Required header for the packages query
        'Authorization' => sprintf('Bearer %s', $token),
    ],
]);

$packagesLimit  = 100;
$versionsLimit  = 100;

$getPackageVersions = <<<GET
query {
    repository(owner: "%s" name: "%s") {
        isPrivate
        packages(first: %d orderBy:{field: CREATED_AT direction: DESC}) {
            nodes {
                name
                versions(first: %d orderBy: {field: CREATED_AT direction: DESC}) {
                    totalCount
                    nodes {
                        id
                        version
                    }
                }
            }
        }
    }
}
GET;

$deletePackageVersion = <<<DELETE
mutation {
    deletePackageVersion(input:{ clientMutationId: "%s" packageVersionId: "%s" }) {
        success
    }
}
DELETE;

try {
    $response = $client->post('graphql', [
        'json' => [
            'query' => sprintf($getPackageVersions, $owner, $repositoryName, $packagesLimit, $versionsLimit)
        ],
    ]);
} catch (ClientException $e) {
    fail(sprintf('[%s] Request for packages failed: %s', $repoNameWithOwner, $e->getResponse()->getBody()->getContents()));
}

$repository = json_decode($response->getBody()->getContents(), true)['data']['repository'] ?? null;

if (null === $repository) {
    fail(sprintf('[%s] Repository not found', $repoNameWithOwner));
} else if (!$repository['isPrivate']) {
    fail(sprintf('[%s] Repository is public, unable to remove package versions', $repoNameWithOwner));
}

$packageNodes = $repository['packages']['nodes'] ?? [];

if (empty($packageNodes)) {
    halt(sprintf('[%s] Repository has no packages', $repoNameWithOwner));
}

$removedPackages = [];

foreach ($packageNodes as $packageNode) {
    $packageName = $packageNode['name'];

    $versionNodes = $packageNode['versions']['nodes'];
    $numVersions = min($versionsLimit, $packageNode['versions']['totalCount']);

    if ($numVersions <= $keepVersions) {
        debug(sprintf('[%s] [%s] Package has fewer than %d versions, no need for removal', $repoNameWithOwner, $packageName, $keepVersions));
        continue;
    }

    for ($i = $keepVersions; $i < $numVersions; $i++) {
        $packageVersionId       = $versionNodes[$i]['id'];
        $packageVersion         = $versionNodes[$i]['version'];
        $packageNameWithVersion = sprintf('%s:%s', $packageName, $packageVersion);

        if ('docker-base-layer' === $packageVersion) {
            // Removing this specific version of a Docker package triggers a bug in GitHub
            // Packages. Keep this safeguard until the bug has been resolved.
            continue;
        }

        if ('latest' === $packageVersion) {
            // Do not remove 'latest' version of package
            continue;
        }

        if (!$removeSemver && isSemanticVersion($packageVersion)) {
            debug(sprintf('[%s] [%s] Semantic versions will not be removed unless remove-semver is set to true', $repoNameWithOwner, $packageNameWithVersion));
            continue;
        }

        debug(sprintf('[%s] [%s] Remove package version', $repoNameWithOwner, $packageNameWithVersion));

        try {
            $client->post('graphql', [
                'headers' => [
                    'Accept' => 'application/vnd.github.package-deletes-preview+json', // Header required for the deletePackageVersion mutation to be available
                ],
                'json' => [
                    'query' => sprintf($deletePackageVersion, $clientId, $packageVersionId)
                ],
            ]);
        } catch (ClientException $e) {
            fail(sprintf('[%s] [%s] Remove package version failed: %s', $repoNameWithOwner, $packageNameWithVersion, $e->getResponse()->getBody()->getContents()));
        }

        $removedPackages[] = $packageNameWithVersion;
    }
}

echo sprintf('::set-output name=removed_package_versions::%s', json_encode(array_map(function(string $version) use ($repoNameWithOwner) : string {
    return sprintf('%s/%s', $repoNameWithOwner, $version);
}, $removedPackages))) . PHP_EOL;
