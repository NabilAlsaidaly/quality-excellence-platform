<?php

declare(strict_types=1);

namespace App\Support\Institution;

final class OrganizationalUnitTreePresenter
{
    /**
     * Convert the internal organizational-unit tree into a public UI structure.
     *
     * @param array{
     *     roots: list<string>,
     *     nodes: array<string, array{
     *         id: int,
     *         public_id: string,
     *         name: string,
     *         parent_public_id: string|null,
     *         children: list<string>
     *     }>
     * } $tree
     * @return array{
     *     roots: list<string>,
     *     nodes: array<string, array{
     *         public_id: string,
     *         name: string,
     *         parent_public_id: string|null,
     *         children: list<string>
     *     }>
     * }
     */
    public function present(array $tree): array
    {
        $nodes = [];

        foreach ($tree['nodes'] as $node) {
            $publicId = $node['public_id'];

            $nodes[$publicId] = [
                'public_id' => $publicId,
                'name' => $node['name'],
                'parent_public_id' => $node['parent_public_id'],
                'children' => $node['children'],
            ];
        }

        return [
            'roots' => $tree['roots'],
            'nodes' => $nodes,
        ];
    }
}
