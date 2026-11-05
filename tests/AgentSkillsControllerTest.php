<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentSkills\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Model\AuthMethodDescriptor;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Provider\AuthMethodDescriptorInterface;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusAgentContracts\Registry\AuthMethodRegistry;
use Koh\SyliusAgentSkills\Agent\AgentSkillsEndpointProvider;
use Koh\SyliusAgentSkills\Catalogue\PublishedSkills;
use Koh\SyliusAgentSkills\Controller\AgentSkillsController;
use Koh\SyliusAgentSkills\Renderer\SkillDocumentRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AgentSkillsControllerTest extends TestCase
{
    public function testIndexDeclaresTheDiscoverySchema(): void
    {
        $index = $this->index();

        self::assertSame(AgentSkillsController::SCHEMA, $index['$schema']);
        self::assertSame('https://schemas.agentskills.io/discovery/0.2.0/schema.json', $index['$schema']);
    }

    public function testEachEntryCarriesTheRequiredFields(): void
    {
        $entry = $this->index()['skills'][0];

        foreach (['name', 'type', 'description', 'url', 'digest'] as $field) {
            self::assertArrayHasKey($field, $entry);
        }

        self::assertSame('product-search', $entry['name']);
        self::assertSame('skill-md', $entry['type']);
        self::assertSame(
            'https://shop.example.com/.well-known/agent-skills/product-search/SKILL.md',
            $entry['url'],
        );
    }

    /**
     * The digest has to match the document this shop actually serves.
     *
     * Computing it here and rendering the document elsewhere is how the two
     * drift; a client that verifies the digest then rejects a perfectly good
     * skill.
     */
    public function testDigestMatchesTheServedDocumentByteForByte(): void
    {
        $declared = $this->index()['skills'][0]['digest'];

        $served = (string) $this->controller()->document('product-search', new Request())->getContent();

        self::assertSame('sha256:'.hash('sha256', $served), $declared);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $declared);
    }

    public function testDocumentIsServedAsMarkdown(): void
    {
        $response = $this->controller()->document('product-search', new Request());

        self::assertSame('text/markdown; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('# Product Search', (string) $response->getContent());
    }

    /** The discovery RFC requires YAML frontmatter with `name` and `description`. */
    public function testDocumentStartsWithFrontmatter(): void
    {
        $document = (string) $this->controller()->document('product-search', new Request())->getContent();

        self::assertStringStartsWith(
            "---\nname: product-search\ndescription: \"Search the catalogue by keyword.\"\n---\n\n# Product Search\n",
            $document,
        );
    }

    /** A colon or a hash in a description must stay text, not become YAML structure. */
    public function testFrontmatterDescriptionIsQuoted(): void
    {
        $skill = new AgentSkill(
            id: 'returns',
            name: 'Returns',
            description: "Start a return: say \"why\" # and when\nwithin 30 days.",
        );

        $document = (string) $this->controller([$this->provider($skill)])->document('returns', new Request())->getContent();

        self::assertStringContainsString(
            'description: "Start a return: say \\"why\\" # and when\\nwithin 30 days."'."\n",
            $document,
        );
    }

    public function testAccessSectionOnlyWhenAnAuthMethodExists(): void
    {
        $without = (string) $this->controller()->document('product-search', new Request())->getContent();
        $with = (string) $this->controller(authMethods: true)->document('product-search', new Request())->getContent();

        self::assertStringNotContainsString('/auth.md', $without);
        self::assertStringContainsString('See https://shop.example.com/auth.md for how to authenticate.', $with);
    }

    public function testDigestCoversTheAccessSection(): void
    {
        $declared = $this->index(authMethods: true)['skills'][0]['digest'];
        $served = (string) $this->controller(authMethods: true)->document('product-search', new Request())->getContent();

        self::assertSame('sha256:'.hash('sha256', $served), $declared);
    }

    public function testUnknownSkillIs404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->document('does-not-exist', new Request());
    }

    /** An index with no skills claims the shop offers none, which is not the same as "none registered". */
    public function testEmptyRegistryIs404RatherThanAnEmptyIndex(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller([])->index(new Request());
    }

    public function testEndpointIsAnnouncedOnlyWhenSkillsExist(): void
    {
        $context = $this->context();

        $with = new AgentSkillsEndpointProvider($this->published([$this->provider($this->skill())]));
        $without = new AgentSkillsEndpointProvider($this->published([]));
        $onlyInvalid = new AgentSkillsEndpointProvider($this->published([
            $this->provider(new AgentSkill(id: 'Not_Valid', name: 'Invalid', description: 'Broken.')),
        ]));

        self::assertCount(1, iterator_to_array($with->getAgentEndpoints($context)));
        self::assertCount(0, iterator_to_array($without->getAgentEndpoints($context)));
        self::assertCount(0, iterator_to_array($onlyInvalid->getAgentEndpoints($context)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'simple' => ['product-search', true];
        yield 'single character' => ['a', true];
        yield 'digits' => ['3d-viewer', true];
        yield '64 characters' => [str_repeat('a', 64), true];
        yield '65 characters' => [str_repeat('a', 65), false];
        yield 'empty' => ['', false];
        yield 'uppercase' => ['Product-Search', false];
        yield 'underscore' => ['product_search', false];
        yield 'leading hyphen' => ['-search', false];
        yield 'trailing hyphen' => ['search-', false];
        yield 'consecutive hyphens' => ['product--search', false];
        yield 'trailing newline' => ["search\n", false];
    }

    #[DataProvider('names')]
    public function testNameRules(string $name, bool $valid): void
    {
        self::assertSame($valid, PublishedSkills::isValidName($name));
    }

    /**
     * An entry whose name the SKILL.md route rejects would point at a 404 — and
     * a strict client may reject the whole index over it.
     */
    public function testSkillsTheRfcDoesNotAdmitAreLeftOut(): void
    {
        $index = $this->index([
            $this->provider($this->skill()),
            $this->provider(new AgentSkill(id: 'Product_Search', name: 'Bad name', description: 'Invalid name.')),
            $this->provider(new AgentSkill(id: 'blank', name: 'Blank', description: '  ')),
            $this->provider(new AgentSkill(id: 'verbose', name: 'Verbose', description: str_repeat('x', 1025))),
            $this->provider(new AgentSkill(id: 'odd-type', name: 'Odd', description: 'Unknown type.', type: 'zip')),
            $this->provider(new AgentSkill(id: 'bad-digest', name: 'Bad', description: 'Bad digest.', url: 'https://cdn.example.com/x.md', digest: 'md5:abc')),
        ]);

        self::assertSame(['product-search'], array_column($index['skills'], 'name'));
    }

    public function testDescriptionAtTheLimitIsKept(): void
    {
        $skill = new AgentSkill(id: 'long', name: 'Long', description: str_repeat('ä', 1024));

        self::assertSame(['long'], array_column($this->index([$this->provider($skill)])['skills'], 'name'));
    }

    /** The registry is shared with A2A and MCP, so a bad skill there is logged, not fatal. */
    public function testLeftOutSkillIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('left out of the agent-skills index'), self::callback(
                static fn (array $context): bool => 'Product_Search' === $context['id'],
            ));

        $published = new PublishedSkills(new AgentSkillRegistry([
            $this->provider(new AgentSkill(id: 'Product_Search', name: 'Bad', description: 'Invalid name.')),
        ]), $logger);

        self::assertSame([], $published->all($this->context()));
    }

    public function testOnlyInvalidSkillsIs404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller([
            $this->provider(new AgentSkill(id: 'Not_Valid', name: 'Invalid', description: 'Broken.')),
        ])->index(new Request());
    }

    /** A provider may point at an artifact it hosts itself. */
    public function testExplicitUrlAndDigestAreRespected(): void
    {
        $skill = new AgentSkill(
            id: 'external',
            name: 'External',
            description: 'Hosted elsewhere.',
            url: 'https://cdn.example.com/skills/external.md',
            digest: 'sha256:'.str_repeat('a', 64),
        );

        $entry = $this->index([$this->provider($skill)])['skills'][0];

        self::assertSame('https://cdn.example.com/skills/external.md', $entry['url']);
        self::assertSame('sha256:'.str_repeat('a', 64), $entry['digest']);
    }

    /** This package would hash its own rendering, not the bytes at the provider's URL. */
    public function testExternalUrlWithoutDigestIsLeftOut(): void
    {
        $skill = new AgentSkill(
            id: 'external',
            name: 'External',
            description: 'Hosted elsewhere.',
            url: 'https://cdn.example.com/skills/external.md',
        );

        $this->expectException(NotFoundHttpException::class);

        $this->index([$this->provider($skill)]);
    }

    public function testArchiveIsListedWithItsOwnUrlAndDigest(): void
    {
        $skill = new AgentSkill(
            id: 'returns-kit',
            name: 'Returns kit',
            description: 'Templates and scripts for returns.',
            type: 'archive',
            url: 'https://cdn.example.com/skills/returns-kit.tar.gz',
            digest: 'sha256:'.str_repeat('b', 64),
        );

        $entry = $this->index([$this->provider($skill)])['skills'][0];

        self::assertSame('archive', $entry['type']);
        self::assertSame('https://cdn.example.com/skills/returns-kit.tar.gz', $entry['url']);
        self::assertSame('sha256:'.str_repeat('b', 64), $entry['digest']);
    }

    /** Without its URL an archive entry would point at a SKILL.md this package renders. */
    public function testArchiveWithoutUrlIsLeftOut(): void
    {
        $skill = new AgentSkill(id: 'kit', name: 'Kit', description: 'Archive.', type: 'archive', digest: 'sha256:'.str_repeat('c', 64));

        $this->expectException(NotFoundHttpException::class);

        $this->index([$this->provider($skill)]);
    }

    /** The local SKILL.md route serves only what the index points at, so its bytes always match a digest. */
    public function testExternallyHostedSkillHasNoLocalDocument(): void
    {
        $skill = new AgentSkill(
            id: 'external',
            name: 'External',
            description: 'Hosted elsewhere.',
            url: 'https://cdn.example.com/skills/external.md',
            digest: 'sha256:'.str_repeat('a', 64),
        );

        $this->expectException(NotFoundHttpException::class);

        $this->controller([$this->provider($skill)])->document('external', new Request());
    }

    /**
     * @param list<AgentSkillProviderInterface>|null $providers
     *
     * @return array<string, mixed>
     */
    private function index(?array $providers = null, bool $authMethods = false): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) $this->controller($providers, $authMethods)->index(new Request())->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /** @param list<AgentSkillProviderInterface>|null $providers */
    private function controller(?array $providers = null, bool $authMethods = false): AgentSkillsController
    {
        return new AgentSkillsController(
            $this->published($providers ?? [$this->provider($this->skill())]),
            new SkillDocumentRenderer(new AuthMethodRegistry($authMethods ? [$this->authMethod()] : [])),
            $this->contextFactory(),
            new AgentJsonResponder(),
        );
    }

    /** @param list<AgentSkillProviderInterface> $providers */
    private function published(array $providers): PublishedSkills
    {
        return new PublishedSkills(new AgentSkillRegistry($providers));
    }

    private function authMethod(): AuthMethodDescriptorInterface
    {
        return new class implements AuthMethodDescriptorInterface {
            public function getAuthMethodDescriptor(AgentContext $context): AuthMethodDescriptor
            {
                return new AuthMethodDescriptor(id: 'oauth2', title: 'OAuth 2.0', documentation: 'Use OAuth.');
            }
        };
    }

    private function skill(): AgentSkill
    {
        return new AgentSkill(
            id: 'product-search',
            name: 'Product Search',
            description: 'Search the catalogue by keyword.',
            tags: ['commerce'],
        );
    }

    private function provider(AgentSkill $skill): AgentSkillProviderInterface
    {
        return new class($skill) implements AgentSkillProviderInterface {
            public function __construct(private AgentSkill $skill)
            {
            }

            public function getAgentSkills(AgentContext $context): iterable
            {
                yield $this->skill;
            }
        };
    }

    private function contextFactory(): AgentContextFactoryInterface
    {
        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn($this->context());

        return $factory;
    }

    private function context(): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Example Shop');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
