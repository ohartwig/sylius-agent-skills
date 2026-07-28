<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAgentSkills\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusAgentSkills\Agent\AgentSkillsEndpointProvider;
use Koh\SyliusAgentSkills\Controller\AgentSkillsController;
use Koh\SyliusAgentSkills\Renderer\SkillDocumentRenderer;
use PHPUnit\Framework\TestCase;
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
        self::assertStringContainsString('/auth.md', (string) $response->getContent());
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

        $with = new AgentSkillsEndpointProvider(new AgentSkillRegistry([$this->provider($this->skill())]));
        $without = new AgentSkillsEndpointProvider(new AgentSkillRegistry([]));

        self::assertCount(1, iterator_to_array($with->getAgentEndpoints($context)));
        self::assertCount(0, iterator_to_array($without->getAgentEndpoints($context)));
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

    /** @param list<AgentSkillProviderInterface>|null $providers */
    private function index(?array $providers = null): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) $this->controller($providers)->index(new Request())->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /** @param list<AgentSkillProviderInterface>|null $providers */
    private function controller(?array $providers = null): AgentSkillsController
    {
        return new AgentSkillsController(
            new AgentSkillRegistry($providers ?? [$this->provider($this->skill())]),
            new SkillDocumentRenderer(),
            $this->contextFactory(),
            new AgentJsonResponder(),
        );
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
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }
}
