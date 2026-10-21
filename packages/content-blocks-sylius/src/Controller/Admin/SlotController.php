<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Controller\Admin;

use ContentBlocks\Sylius\Entity\ContentSlot;
use ContentBlocks\Sylius\Locale\ShopLocales;
use ContentBlocks\Sylius\Repository\ContentSlotRepository;
use ContentBlocks\Sylius\Routing\AdminUrls;
use ContentBlocks\Sylius\Routing\ShopUrls;
use ContentBlocks\Sylius\Slot\SlotRenderer;
use ContentBlocks\Sylius\Slot\SlotType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The builder for a native page's slot. Opening the screen writes nothing:
 * the slot and its area are created by an explicit POST.
 */
final class SlotController
{
    public const CSRF_ID = 'content_blocks_sylius_slot';

    /**
     * @param ObjectRepository<object> $products
     * @param ObjectRepository<object> $taxons
     * @param ObjectRepository<object> $channels
     */
    public function __construct(
        private readonly ContentSlotRepository $slots,
        private readonly EntityManagerInterface $em,
        private readonly ObjectRepository $products,
        private readonly ObjectRepository $taxons,
        private readonly ObjectRepository $channels,
        private readonly SlotRenderer $renderer,
        private readonly ShopUrls $shopUrls,
        private readonly AdminUrls $adminUrls,
        private readonly ShopLocales $locales,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly Environment $twig,
    ) {
    }

    public function show(string $type, string $subject): Response
    {
        $label = $this->subjectLabel($type, $subject);
        $slot = $this->slots->findOneByTypeAndSubject($type, $subject);
        $address = $this->shopUrls->slot($slot ?? new ContentSlot($type, $subject), $this->locales->codes()[0]);

        return new Response($this->twig->render('@ContentBlocksSyliusPlugin/admin/page.html.twig', [
            'title' => 'content_blocks_sylius.ui.slot.' . $type,
            'hook' => 'content_blocks_sylius.admin.slot',
            'context' => [
                'slot' => $slot,
                'type' => $type,
                'subject' => $subject,
                'subject_label' => $label,
                'shop_url' => $address,
            ],
        ]));
    }

    public function create(Request $request, string $type, string $subject): Response
    {
        $token = new CsrfToken(self::CSRF_ID, (string) $request->request->get('_csrf_token'));
        if (!$this->csrf->isTokenValid($token)) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $this->subjectLabel($type, $subject);

        if ($this->slots->findOneByTypeAndSubject($type, $subject) === null) {
            $this->em->persist(new ContentSlot($type, $subject));
            $this->em->flush();
        }

        return new RedirectResponse($this->adminUrls->slot($type, $subject));
    }

    /** The name of the slot's subject; a 404 for an unknown or disabled one. */
    private function subjectLabel(string $type, string $subject): string
    {
        if (!$this->renderer->isEnabled($type)) {
            throw new NotFoundHttpException(sprintf('Slots of type "%s" are disabled.', $type));
        }
        $repository = match ($type) {
            SlotType::HOMEPAGE => $this->channels,
            SlotType::PRODUCT => $this->products,
            default => $this->taxons,
        };
        $entity = $repository->findOneBy(['code' => $subject]);
        if ($entity === null) {
            throw new NotFoundHttpException(sprintf('No %s with code "%s".', $type, $subject));
        }

        return method_exists($entity, 'getName') ? (string) $entity->getName() : $subject;
    }
}
