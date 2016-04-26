<?php
namespace Neos\MarketPlace\Property\TypeConverter;

/*
 * This file is part of the Neos.MarketPlace package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Github\Client;
use Github\Exception\ApiLimitExceedException;
use Github\Exception\RuntimeException;
use Github\HttpClient\CachedHttpClient;
use Neos\MarketPlace\Domain\Model\Slug;
use Neos\MarketPlace\Domain\Model\Storage;
use Neos\MarketPlace\Utility\VersionNumber;
use Packagist\Api\Result\Package;
use TYPO3\Eel\FlowQuery\FlowQuery;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Property\Exception\InvalidPropertyMappingConfigurationException;
use TYPO3\Flow\Property\Exception\TypeConverterException;
use TYPO3\Flow\Property\PropertyMappingConfigurationInterface;
use TYPO3\Flow\Property\TypeConverter\AbstractTypeConverter;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\NodeTemplate;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;

/**
 * Convert package from packagist to node
 *
 * @api
 */
class PackageConverter extends AbstractTypeConverter
{
    /**
     * @var string
     */
    const STORAGE = 'storage';

    /**
     * @var NodeTypeManager
     * @Flow\Inject
     */
    protected $nodeTypeManager;

    /**
     * @var array<string>
     */
    protected $sourceTypes = [Package::class];

    /**
     * @var string
     */
    protected $targetType = NodeInterface::class;

    /**
     * @Flow\InjectConfiguration(path="github")
     * @var string
     */
    protected $githubSettings;

    /**
     * Converts $source to a node
     *
     * @param string|integer|array $source the string to be converted to a \DateTime object
     * @param string $targetType must be "DateTime"
     * @param array $convertedChildProperties not used currently
     * @param PropertyMappingConfigurationInterface $configuration
     * @return NodeInterface
     * @throws TypeConverterException
     */
    public function convertFrom($source, $targetType, array $convertedChildProperties = [], PropertyMappingConfigurationInterface $configuration = null)
    {
        /** @var Package $package */
        $package = $source;
        $storage = $this->getStorage($configuration);
        $vendorNode = $storage->getPackageVendor($package->getName());
        $packageNode = $vendorNode->getPackage($package->getName());
        if ($packageNode === null) {
            $node = $this->create($package, $vendorNode);
        } else {
            $node = $this->update($package, $packageNode);
        }
        $this->createOrUpdateMaintainers($package, $node);
        $this->createOrUpdateVersions($package, $node);

        $this->handleDownloads($package, $node);
        $this->handleGithubMetrics($package, $node);

        $this->handleAbandonedPackageOrVersion($package, $node);
        return $node;
    }

    /**
     * @param Package $package
     * @param NodeInterface $node
     */
    protected function handleDownloads(Package $package, NodeInterface $node)
    {
        $downloads = $package->getDownloads();
        if (!$downloads instanceof Package\Downloads) {
            return;
        }
        $this->updateNodeProperties($node, [
            'downloadTotal' => $downloads->getTotal(),
            'downloadMonthly' => $downloads->getMonthly(),
            'downloadDaily' => $downloads->getDaily(),
        ]);
    }

    /**
     * @param Package $package
     * @param NodeInterface $node
     */
    protected function handleGithubMetrics(Package $package, NodeInterface $node)
    {
        if (isset($package->abandoned) && $package->abandoned === true) {
            $this->resetGithubMetrics($node);
        } else {
            $repository = $package->getRepository();
            if (strpos($repository, 'github.com') === false) {
                return;
            }
            // todo make it a bit more clever
            $repository = str_replace('.git', '', $repository);
            preg_match("#(.*)://github.com/(.*)#", $repository, $matches);
            list($organization, $repository) = explode('/', $matches[2]);
            $client = new Client(
                new CachedHttpClient(['cache_dir' => $this->githubSettings['cacheDirectory']])
            );
            $client->authenticate($this->githubSettings['account'], $this->githubSettings['password']);
            try {
                $meta = $client->repositories()->show($organization, $repository);
                if (!is_array($meta)) {
                    return;
                }
                $this->updateNodeProperties($node, [
                    'githubStargazers' => (integer)Arrays::getValueByPath($meta, 'stargazers_count'),
                    'githubWatchers' => (integer)Arrays::getValueByPath($meta, 'watchers_count'),
                    'githubForks' => (integer)Arrays::getValueByPath($meta, 'forks_count'),
                    'githubIssues' => (integer)Arrays::getValueByPath($meta, 'open_issues_count'),
                    'githubAvatar' => trim(Arrays::getValueByPath($meta, 'organization.avatar_url'))
                ]);
                $this->handleGithubReadme($organization, $repository, $node);
            } catch (ApiLimitExceedException $exception) {
                // Skip the processing if we hit the API rate limit
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'Not Found') {
                    // todo special handling of not found repository ?
                    $this->resetGithubMetrics($node);
                }
            }
        }
    }

    /**
     * @param string $oganization
     * @param string $repository
     * @param NodeInterface $node
     */
    protected function handleGithubReadme($oganization, $repository, NodeInterface $node)
    {
        try {
            $httpClient = new CachedHttpClient([
                'cache_dir' => $this->githubSettings['cacheDirectory']
            ]);
            $httpClient->setHeaders([
                'Accept' => 'application/vnd.github.VERSION.html'
            ]);
            $client = new Client($httpClient);
            $client->authenticate($this->githubSettings['account'], $this->githubSettings['password']);
            $readme = trim($client->repository()->readme($oganization, $repository));
            if ($readme === '') {
                return;
            }
            $query = new FlowQuery([$node]);
            $readmeNode = $query
                ->find('readme')
                ->get(0);

            if ($readmeNode === null) {
                return;
            }
            $readmeNode->setProperty('source', $readme);
        } catch (\Exception $exception) {

        }
    }

    /**
     * @param NodeInterface $node
     */
    protected function resetGithubMetrics(NodeInterface $node)
    {
        $this->updateNodeProperties($node, [
            'githubStargazers' => 0,
            'githubWatchers' => 0,
            'githubForks' => 0,
            'githubIssues' => 0,
            'githubAvatar' => null
        ]);
    }

    /**
     * @param Package $package
     * @param NodeInterface $node
     */
    protected function handleAbandonedPackageOrVersion(Package $package, NodeInterface $node)
    {
        if (isset($package->abandoned) && trim($package->abandoned) !== '') {
            if (trim($node->getProperty('abandoned')) === '') {
                $node->setProperty('abandoned', $package->abandoned);
                $this->emitPackageAbandoned($node);
            } else {
                $node->setProperty('abandoned', $package->abandoned);
            }
        }
    }

    /**
     * @param Package $package
     * @param NodeInterface $parentNode
     * @return NodeInterface
     */
    protected function create(Package $package, NodeInterface $parentNode)
    {
        $name = Slug::create($package->getName());
        $nodeTemplate = new NodeTemplate();
        $time = \DateTime::createFromFormat(\DateTime::ISO8601, $package->getTime());
        $nodeTemplate->setName($name);
        $nodeTemplate->setNodeType($this->nodeTypeManager->getNodeType('Neos.MarketPlace:Package'));
        $data = [
            'uriPathSegment' => $name,
            'title' => $package->getName(),
            'description' => $package->getDescription(),
            'time' => $time,
            'type' => $package->getType(),
            'repository' => $package->getRepository(),
            'favers' => $package->getFavers()
        ];
        $this->setNodeTemplateProperties($nodeTemplate, $data);
        $node = $parentNode->createNodeFromTemplate($nodeTemplate);
        return $node;
    }

    /**
     * @param Package $package
     * @param NodeInterface $node
     * @return NodeInterface
     */
    protected function update(Package $package, NodeInterface $node)
    {
        $this->updateNodeProperties($node, [
            'description' => $package->getDescription(),
            'time' => \DateTime::createFromFormat(\DateTime::ISO8601, $package->getTime()),
            'type' => $package->getType(),
            'repository' => $package->getRepository(),
            'favers' => $package->getFavers()
        ]);
        return $node;
    }

    /**
     * @param Package $package
     * @param NodeInterface $node
     */
    protected function createOrUpdateMaintainers(Package $package, NodeInterface $node)
    {
        $upstreamMaintainers = array_map(function (Package\Maintainer $maintainer) {
            return Slug::create($maintainer->getName());
        }, $package->getMaintainers());
        $maintainerStorage = $node->getNode('maintainers');
        $maintainers = new FlowQuery([$maintainerStorage]);
        $maintainers = $maintainers->children('[instanceof Neos.MarketPlace:Maintainer]');
        foreach ($maintainers as $maintainer) {
            /** @var NodeInterface $maintainer */
            if (in_array($maintainer->getName(), $upstreamMaintainers)) {
                continue;
            }
            $maintainer->remove();
        }

        foreach ($package->getMaintainers() as $maintainer) {
            /** @var Package\Maintainer $maintainer */
            $name = Slug::create($maintainer->getName());
            $node = $maintainerStorage->getNode($name);
            $data = [
                'title' => $maintainer->getName(),
                'email' => $maintainer->getEmail(),
                'homepage' => $maintainer->getHomepage()
            ];
            if ($node === null) {
                $nodeTemplate = new NodeTemplate();
                $nodeTemplate->setNodeType($this->nodeTypeManager->getNodeType('Neos.MarketPlace:Maintainer'));
                $nodeTemplate->setName($name);
                $this->setNodeTemplateProperties($nodeTemplate, $data);
                $maintainerStorage->createNodeFromTemplate($nodeTemplate);
            } else {
                $this->updateNodeProperties($node, $data);
            }
        }
    }

    /**
     * @param Package $package
     * @param NodeInterface $node
     */
    protected function createOrUpdateVersions(Package $package, NodeInterface $node)
    {
        $upstreamVersions = array_map(function ($version) {
            return Slug::create($version);
        }, array_keys($package->getVersions()));
        $versionStorage = $node->getNode('versions');
        $versions = new FlowQuery([$versionStorage]);
        $versions = $versions->children('[instanceof Neos.MarketPlace:Version]');
        foreach ($versions as $version) {
            /** @var NodeInterface $version */
            if (in_array($version->getName(), $upstreamVersions)) {
                continue;
            }
            $version->remove();
        }


        /** @var Package\Version $version */
        foreach ($package->getVersions() as $version) {
            $versionStability = VersionNumber::isVersionStable($version->getVersionNormalized());
            $stabilityLevel = VersionNumber::getStabilityLevel($version->getVersionNormalized());
            $versionNormalized = VersionNumber::toInteger($version->getVersionNormalized());

            /** @var Package\Version $version */
            $name = Slug::create($version->getVersion());
            $node = $versionStorage->getNode($name);
            $data = [
                'version' => $version->getVersion(),
                'description' => $version->getDescription(),
                'keywords' => $this->arrayToStringCaster($version->getKeywords()),
                'homepage' => $version->getHomepage(),
                'versionNormalized' => $versionNormalized,
                'stability' => $versionStability,
                'stabilityLevel' => $stabilityLevel,
                'license' => $this->arrayToStringCaster($version->getLicense()),
                'type' => $version->getType(),
                'time' => \DateTime::createFromFormat(\DateTime::ISO8601, $version->getTime()),
                'provide' => $version->getProvide(),
                'bin' => $this->arrayToJsonCaster($version->getBin()),
                'require' => $this->arrayToJsonCaster($version->getRequire()),
                'requireDev' => $this->arrayToJsonCaster($version->getRequireDev()),
                'suggest' => $this->arrayToJsonCaster($version->getSuggest()),
                'conflict' => $this->arrayToJsonCaster($version->getConflict()),
                'replace' => $this->arrayToJsonCaster($version->getReplace()),
            ];
            switch ($stabilityLevel) {
                case 'stable':
                    $nodeType = $this->nodeTypeManager->getNodeType('Neos.MarketPlace:ReleasedVersion');
                    break;
                case 'dev':
                    $nodeType = $this->nodeTypeManager->getNodeType('Neos.MarketPlace:DevelopmentVersion');
                    break;
                default:
                    $nodeType = $this->nodeTypeManager->getNodeType('Neos.MarketPlace:PrereleasedVersion');
            }
            if ($node === null) {
                $nodeTemplate = new NodeTemplate();
                $nodeTemplate->setNodeType($nodeType);
                $nodeTemplate->setName($name);
                $this->setNodeTemplateProperties($nodeTemplate, $data);
                $node = $versionStorage->createNodeFromTemplate($nodeTemplate);
            } else {
                if ($node->getNodeType()->getName() !== $nodeType->getName()) {
                    $node->setNodeType($nodeType);
                }
                $this->updateNodeProperties($node, $data);
            }

            if ($version->getSource()) {
                $source = $node->getNode('source');
                $this->updateNodeProperties($source, [
                    'type' => $version->getSource()->getType(),
                    'reference' => $version->getSource()->getReference(),
                    'url' => $version->getSource()->getUrl(),
                ]);
            }

            if ($version->getDist()) {
                $dist = $node->getNode('dist');
                $this->updateNodeProperties($dist, [
                    'type' => $version->getDist()->getType(),
                    'reference' => $version->getDist()->getReference(),
                    'url' => $version->getDist()->getUrl(),
                    'shasum' => $version->getDist()->getShasum(),
                ]);
            }

            $this->handleAbandonedPackageOrVersion($package, $node);
        }
    }

    /**
     * @param NodeTemplate $template
     * @param array $data
     */
    protected function setNodeTemplateProperties(NodeTemplate $template, array $data)
    {
        foreach ($data as $propertyName => $propertyValue) {
            $template->setProperty($propertyName, $propertyValue);
        }
    }

    /**
     * @param NodeInterface $node
     * @param array $data
     */
    protected function updateNodeProperties(NodeInterface $node, array $data)
    {
        foreach ($data as $propertyName => $propertyValue) {
            $this->updateNodeProperty($node, $propertyName, $propertyValue);
        }
    }

    /**
     * @param NodeInterface $node
     * @param string $propertyName
     * @param mixed $propertyValue
     */
    protected function updateNodeProperty(NodeInterface $node, $propertyName, $propertyValue)
    {
        if (isset($node->getProperties()[$propertyName])) {
            if ($propertyValue instanceof \DateTime) {
                if ($node->getProperties()[$propertyName]->getTimestamp() === $propertyValue->getTimestamp()) {
                    return;
                }
            } else {
                if ($node->getProperties()[$propertyName] === $propertyValue) {
                    return;
                }
            }
        }
        $node->setProperty($propertyName, $propertyValue);
    }

    /**
     * Determines the default date format to use for the conversion.
     * If no format is specified in the mapping configuration DEFAULT_DATE_FORMAT is used.
     *
     * @param PropertyMappingConfigurationInterface $configuration
     * @return Storage
     * @throws InvalidPropertyMappingConfigurationException
     */
    protected function getStorage(PropertyMappingConfigurationInterface $configuration = null)
    {
        if ($configuration === null) {
            throw new InvalidPropertyMappingConfigurationException('Missing property configuration', 1457516367);
        }
        $storage = $configuration->getConfigurationValue(PackageConverter::class, self::STORAGE);
        if (!$storage instanceof Storage) {
            throw new InvalidPropertyMappingConfigurationException('Storage must be a NodeInterface instances', 1457516377);
        }
        return $storage;
    }

    /**
     * @param array $value
     * @return string
     */
    protected function arrayToStringCaster($value)
    {
        $value = $value ?: [];
        return implode(', ', $value);
    }

    /**
     * @param array $value
     * @return string
     */
    protected function arrayToJsonCaster($value)
    {
        return $value ? json_encode($value, JSON_PRETTY_PRINT) : null;
    }

    /**
     * Signals that a node was abandoned.
     *
     * @Flow\Signal
     * @param NodeInterface $node
     * @return void
     */
    protected function emitPackageAbandoned(NodeInterface $node)
    {
    }
}
