<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

/**
 * Capability gate for the optional ClasseViva integration.
 *
 * A page must opt in before bootstrap validates the token or renders the
 * global re-authentication dialog. Ordinary UDA, Classroom and GitHub pages
 * therefore remain usable when ClasseViva has never been connected.
 */
final class ClasseVivaCapability
{
    public const CONSTANT = 'REQUIRES_CLASSEVIVA';

    public static function requested(): bool
    {
        return defined(self::CONSTANT) && constant(self::CONSTANT) === true;
    }

    /**
     * Verifica se una UDA contiene almeno un gruppo con un collegamento
     * ClasseViva attivo. Il controllo è volutamente limitato all'utente
     * corrente e al contesto indicato, così da poterlo usare come capability
     * condizionale prima che una pagina inizi a interrogare ClasseViva.
     */
    public static function hasMappedUda(
        DatabaseAdapterInterface $db,
        string $userId,
        string $udaId
    ): bool {
        $userId = trim($userId);
        $udaId = trim($udaId);
        if ($userId === '' || $udaId === '') {
            return false;
        }

        foreach ($db->findWhere('UDA_GRUPPI', [
            'id_uda' => $udaId,
            'id_utente' => $userId,
        ]) as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }
            $groupId = trim((string)($assignment['id_gruppo'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            foreach ($db->findWhere('GRUPPI_INTEGRAZIONI', [
                'id_utente' => $userId,
                'id_gruppo' => $groupId,
                'provider' => 'classeviva',
            ]) as $integration) {
                if (!is_array($integration)
                    || ($integration['stato'] ?? 'attivo') === 'disattivo'
                    || trim((string)($integration['external_context_id'] ?? '')) === '') {
                    continue;
                }
                return true;
            }
        }

        return false;
    }
}
