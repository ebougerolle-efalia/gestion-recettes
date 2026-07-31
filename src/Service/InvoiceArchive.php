<?php
namespace App\Service;

/**
 * Conservation des fichiers de facture reçus.
 *
 * Une facture illisible pour le moteur reste une pièce comptable : le fichier
 * d'origine doit rester consultable, et rejouable si le parseur progresse. Le
 * stockage se fait sous var/, hors de la racine web — une facture fournisseur
 * n'a rien à faire derrière une URL publique.
 *
 * Le nom du fichier est son empreinte : deux réceptions du même document
 * n'occupent qu'une seule place, et le contenu est vérifiable.
 */
class InvoiceArchive
{
    public function __construct(private string $projectDir) {}

    public function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Écrit le fichier et renvoie son chemin relatif au dossier d'archive.
     *
     * Rangé par année : un dossier plat finit par gêner l'exploitation courante
     * (sauvegardes, inspection) au bout de quelques milliers de factures.
     */
    public function store(string $content, string $hash, string $mime): string
    {
        $ext      = $mime === 'application/pdf' ? 'pdf' : 'xml';
        $relative = sprintf('%s/%s.%s', date('Y'), $hash, $ext);
        $absolute = $this->root() . '/' . $relative;

        if (!is_dir(\dirname($absolute)) && !@mkdir(\dirname($absolute), 0775, true) && !is_dir(\dirname($absolute))) {
            throw new \RuntimeException(sprintf('Dossier d\'archive impossible à créer : %s', \dirname($absolute)));
        }

        // Déjà présent : même empreinte, même contenu, rien à réécrire.
        if (!is_file($absolute) && @file_put_contents($absolute, $content) === false) {
            throw new \RuntimeException(sprintf('Écriture impossible dans l\'archive : %s', $absolute));
        }

        return $relative;
    }

    public function absolutePath(string $relative): string
    {
        return $this->root() . '/' . ltrim($relative, '/\\');
    }

    public function exists(string $relative): bool
    {
        return is_file($this->absolutePath($relative));
    }

    public function read(string $relative): ?string
    {
        $path = $this->absolutePath($relative);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    private function root(): string
    {
        return $this->projectDir . '/var/invoices';
    }
}
