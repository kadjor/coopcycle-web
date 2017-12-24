<?php

namespace AppBundle\Controller;

use AppBundle\Controller\Utils\AccessControlTrait;
use AppBundle\Controller\Utils\DeliveryTrait;
use AppBundle\Controller\Utils\LocalBusinessTrait;
use AppBundle\Controller\Utils\OrderTrait;
use AppBundle\Controller\Utils\RestaurantTrait;
use AppBundle\Controller\Utils\StoreTrait;
use AppBundle\Controller\Utils\UserTrait;
use AppBundle\Form\RestaurantAdminType;
use AppBundle\Entity\ApiUser;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\Menu;
use AppBundle\Entity\Restaurant;
use AppBundle\Entity\Order;
use AppBundle\Entity\Store;
use AppBundle\Entity\Zone;
use AppBundle\Form\DeliveryType;
use AppBundle\Form\MenuCategoryType;
use AppBundle\Form\PricingRuleSetType;
use AppBundle\Form\RestaurantMenuType;
use AppBundle\Form\RestaurantType;
use AppBundle\Form\StoreType;
use AppBundle\Form\UpdateProfileType;
use AppBundle\Form\GeoJSONUploadType;
use AppBundle\Form\ZoneCollectionType;
use AppBundle\Service\DeliveryPricingManager;
use AppBundle\Utils\PricingRuleSet;
use Doctrine\Common\Collections\ArrayCollection;
use FOS\UserBundle\Model\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class AdminController extends Controller
{
    const ITEMS_PER_PAGE = 20;

    use AccessControlTrait;
    use DeliveryTrait;
    use OrderTrait;
    use LocalBusinessTrait;
    use RestaurantTrait;
    use StoreTrait;
    use UserTrait;

    /**
     * @Route("/admin", name="admin_index")
     * @Template("@App/Admin/dashboard.html.twig")
     */
    public function indexAction(Request $request)
    {
        return array();
    }

    protected function getOrderList(Request $request)
    {
        $orderRepository = $this->getDoctrine()->getRepository(Order::class);

        $showCanceled = false;
        if ($request->query->has('show_canceled')) {
            $showCanceled = $request->query->getBoolean('show_canceled');
        } elseif ($request->cookies->has('__show_canceled')) {
            $showCanceled = $request->cookies->getBoolean('__show_canceled');
        }

        $statusList = [
            Order::STATUS_WAITING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_REFUSED,
            Order::STATUS_READY,
        ];
        if ($showCanceled) {
            $statusList[] = Order::STATUS_CANCELED;
        }

        $countAll = $orderRepository->countByStatus($statusList);

        $pages = ceil($countAll / self::ITEMS_PER_PAGE);
        $page = $request->query->get('p', 1);

        $offset = self::ITEMS_PER_PAGE * ($page - 1);

        $orders = $orderRepository->findByStatus($statusList, [
            'updatedAt' => 'DESC',
            'createdAt' => 'DESC'
        ], self::ITEMS_PER_PAGE, $offset);

        return [ $orders, $pages, $page ];
    }

    /**
     * @Route("/admin/dashboard", name="admin_dashboard")
     * @Template
     */
    public function dashboardAction(Request $request)
    {
        return array();
    }

    /**
     * @Route("/admin/users", name="admin_users")
     * @Template
     */
    public function usersAction(Request $request)
    {
        $users = $this->getDoctrine()
            ->getRepository('AppBundle:ApiUser')
            ->findBy([], ['id' => 'DESC']);

        return array(
            'users' => $users,
        );
    }

    /**
     * @Route("/admin/user/{username}", name="admin_user_details")
     * @Template
     */
    public function userAction($username, Request $request)
    {
        // @link https://symfony.com/doc/current/bundles/FOSUserBundle/user_manager.html
        $userManager = $this->get('fos_user.user_manager');

        $user = $userManager->findUserByUsername($username);

        return [
            'user' => $user,
        ];
    }

    /**
     * @Route("/admin/user/{username}/edit", name="admin_user_edit")
     * @Template
     */
    public function userEditAction($username, Request $request)
    {
        // @link https://symfony.com/doc/current/bundles/FOSUserBundle/user_manager.html
        $userManager = $this->get('fos_user.user_manager');

        $user = $userManager->findUserByUsername($username);

        // Roles that can be edited by admin
        $editableRoles = ['ROLE_ADMIN', 'ROLE_COURIER', 'ROLE_RESTAURANT'];

        $originalRoles = array_filter($user->getRoles(), function($role) use ($editableRoles) {
            return in_array($role, $editableRoles);
        });

        $editForm = $this->createForm(UpdateProfileType::class, $user, [
            'with_restaurants' => true,
            'with_roles' => true,
            'editable_roles' => $editableRoles
        ]);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {

            $userManager = $this->getDoctrine()->getManagerForClass(ApiUser::class);

            $user = $editForm->getData();

            $roles = $editForm->get('roles')->getData();

            $rolesToRemove = array_diff($originalRoles, $roles);

            foreach ($rolesToRemove as $role) {
                $user->removeRole($role);
            }

            foreach ($roles as $role) {
                if (!$user->hasRole($role)) {
                    $user->addRole($role);
                }
            }

            $userManager->persist($user);
            $userManager->flush();

            return $this->redirectToRoute('admin_user_details', ['username' => $user->getUsername()]);
        }

        return [
            'form' => $editForm->createView(),
            'user' => $user,
        ];
    }

    /**
     * @Route("/admin/user/{username}/tracking", name="admin_user_tracking")
     * @Template("@App/User/tracking.html.twig")
     */
    public function userTrackingAction($username, Request $request)
    {
        $userManager = $this->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($username);

        return $this->userTracking($user, 'admin');
    }

    protected function getRestaurantList(Request $request)
    {
        $repository = $this->getDoctrine()->getRepository(Restaurant::class);

        $countAll = $repository
            ->createQueryBuilder('r')->select('COUNT(r)')
            ->getQuery()->getSingleScalarResult();

        $pages = ceil($countAll / self::ITEMS_PER_PAGE);
        $page = $request->query->get('p', 1);

        $offset = self::ITEMS_PER_PAGE * ($page - 1);

        $restaurants = $repository->findBy([], [
            'id' => 'DESC',
        ], self::ITEMS_PER_PAGE, $offset);

        return [ $restaurants, $pages, $page ];
    }

    /**
     * @Route("/admin/deliveries", name="admin_deliveries")
     * @Template()
     */
    public function deliveriesAction(Request $request)
    {
        $repository = $this->getDoctrine()->getRepository('AppBundle:Delivery');

        // @link https://symfony.com/doc/current/bundles/FOSUserBundle/user_manager.html
        $userManager = $this->get('fos_user.user_manager');

        $couriers = array_filter($userManager->findUsers(), function (UserInterface $user) {
            return $user->hasRole('ROLE_COURIER');
        });

        usort($couriers, function (UserInterface $a, UserInterface $b) {
            return $a->getUsername() < $b->getUsername() ? -1 : 1;
        });

        return [
            'couriers' => $couriers,
            'deliveries' => $repository->findBy([], ['date' => 'DESC']),
            'routes' => $this->getDeliveryRoutes(),
        ];
    }

    /**
     * @Route("/admin/deliveries/new", name="admin_deliveries_new")
     * @Template()
     */
    public function newDeliveryAction(Request $request)
    {
        $delivery = new Delivery();

        $form = $this->createForm(DeliveryType::class, $delivery);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $delivery = $form->getData();

            $em = $this->getDoctrine()->getManagerForClass('AppBundle:Delivery');

            if ($delivery->getDate() < new \DateTime()) {
                $form->get('date')->addError(new FormError('The date is in the past'));
            }

            if ($form->isValid()) {

                $this->get('delivery_service.default')->calculate($delivery);
                $this->get('coopcycle.delivery.manager')->applyTaxes($delivery);

                $em->persist($delivery);
                $em->flush();

                return $this->redirectToRoute('admin_deliveries');
            }
        }

        return [
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/admin/deliveries/{id}/dispatch", methods={"POST"}, name="admin_delivery_dispatch")
     */
    public function dispatchDeliveryAction($id, Request $request)
    {
        $delivery = $this->getDoctrine()
            ->getRepository(Delivery::class)
            ->find($id);

        $this->accessControl($delivery);

        $userManager = $this->get('fos_user.user_manager');

        $userId = $request->request->get('courier');
        $courier = $userManager->findUserBy(['id' => $userId]);

        $this->get('coopcycle.delivery.manager')->dispatch($delivery, $courier);

        $this->getDoctrine()
            ->getManagerForClass(Delivery::class)
            ->flush();

        return $this->redirectToRoute('admin_deliveries');
    }

    protected function getDeliveryRoutes()
    {
        return [
            'list'     => 'admin_deliveries',
            'dispatch' => 'admin_delivery_dispatch',
            'pick'     => 'admin_delivery_pick',
            'deliver'  => 'admin_delivery_deliver'
        ];
    }

    /**
     * @Route("/admin/menu/categories", name="admin_menu_categories")
     * @Template
     */
    public function menuCategoriesAction(Request $request)
    {
        $categories = $this->getDoctrine()
            ->getRepository(Menu\MenuCategory::class)
            ->findBy([], ['name' => 'ASC']);

        return [
            'categories' => $categories,
        ];
    }

    /**
     * @Route("/admin/menu/categories/new", name="admin_menu_category_new")
     * @Template
     */
    public function newMenuCategoryAction(Request $request)
    {
        $category = new Menu\MenuCategory();

        $form = $this->createForm(MenuCategoryType::class, $category);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $category = $form->getData();
            $this->getDoctrine()->getManagerForClass(Menu\MenuCategory::class)->persist($category);
            $this->getDoctrine()->getManagerForClass(Menu\MenuCategory::class)->flush();

            return $this->redirectToRoute('admin_menu_categories');
        }

        return [
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/admin/settings/taxation", name="admin_taxation_settings")
     * @Template
     */
    public function taxationSettingsAction(Request $request)
    {
        $taxCategoryRepository = $this->get('sylius.repository.tax_category');

        $taxCategories = $taxCategoryRepository->findAll();

        return [
            'taxCategories' => $taxCategories
        ];
    }

    /**
     * @Route("/admin/deliveries/pricing", name="admin_deliveries_pricing")
     * @Template("AppBundle:Admin:pricing.html.twig")
     */
    public function pricingRuleSetsAction(Request $request)
    {
        $ruleSets = $this->getDoctrine()
            ->getRepository(Delivery\PricingRuleSet::class)
            ->findAll();

        return [
            'ruleSets' => $ruleSets
        ];
    }

    private function renderPricingRuleSetForm(Delivery\PricingRuleSet $ruleSet, Request $request)
    {
        $originalRules = new ArrayCollection();
        foreach ($ruleSet->getRules() as $rule) {
            $originalRules->add($rule);
        }

        $form = $this->createForm(PricingRuleSetType::class, $ruleSet);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $ruleSet = $form->getData();

            $em = $this->getDoctrine()->getManagerForClass(Delivery\PricingRule::class);

            foreach ($originalRules as $originalRule) {
                if (!$ruleSet->getRules()->contains($originalRule)) {
                    $em->remove($originalRule);
                }
            }

            foreach ($ruleSet->getRules() as $rule) {
                $rule->setRuleSet($ruleSet);
            }

            if (null === $ruleSet->getId()) {
                $em->persist($ruleSet);
            }

            $em->flush();

            return $this->redirectToRoute('admin_deliveries_pricing');
        }

        return [
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/admin/deliveries/pricing/new", name="admin_deliveries_pricing_ruleset_new")
     * @Template("AppBundle:Admin:pricingRuleSet.html.twig")
     */
    public function newPricingRuleSetAction(Request $request)
    {
        $ruleSet = new Delivery\PricingRuleSet();

        return $this->renderPricingRuleSetForm($ruleSet, $request);
    }

    /**
     * @Route("/admin/deliveries/pricing/{id}", name="admin_deliveries_pricing_ruleset")
     * @Template("AppBundle:Admin:pricingRuleSet.html.twig")
     */
    public function pricingRuleSetAction($id, Request $request)
    {
        $ruleSet = $this->getDoctrine()
            ->getRepository(Delivery\PricingRuleSet::class)
            ->find($id);

        return $this->renderPricingRuleSetForm($ruleSet, $request);
    }

    /**
     * @Route("/admin/deliveries/pricing/calculate", name="admin_deliveries_pricing_calculate")
     * @Template
     */
    public function deliveriesPricingCalculateAction(Request $request)
    {
        $deliveryManager = $this->get('coopcycle.delivery.manager');

        $delivery = new Delivery();
        $delivery->setDistance($request->query->get('distance'));

        return new JsonResponse($deliveryManager->getPrice($delivery));
    }

    /**
     * @Route("/admin/zones/{id}/delete", methods={"POST"}, name="admin_zone_delete")
     * @Template
     */
    public function deleteZoneAction($id, Request $request)
    {
        $zone = $this->getDoctrine()->getRepository(Zone::class)->find($id);

        $this->getDoctrine()->getManagerForClass(Zone::class)->remove($zone);
        $this->getDoctrine()->getManagerForClass(Zone::class)->flush();

        return $this->redirectToRoute('admin_zones');
    }

    /**
     * @Route("/admin/zones", name="admin_zones")
     * @Template
     */
    public function zonesAction(Request $request)
    {
        $zoneCollection = new \stdClass();
        $zoneCollection->zones = [];

        $geojson = new \stdClass();
        $geojson->features = [];

        $uploadForm = $this->createForm(GeoJSONUploadType::class, $geojson);
        $zoneCollectionForm = $this->createForm(ZoneCollectionType::class, $zoneCollection);

        $zoneCollectionForm->handleRequest($request);
        if ($zoneCollectionForm->isSubmitted() && $zoneCollectionForm->isValid()) {

            $zoneCollection = $zoneCollectionForm->getData();

            foreach ($zoneCollection->zones as $zone) {
                $this->getDoctrine()->getManagerForClass(Zone::class)->persist($zone);
            }

            $this->getDoctrine()->getManagerForClass(Zone::class)->flush();

            return $this->redirectToRoute('admin_zones');
        }

        $uploadForm->handleRequest($request);
        if ($uploadForm->isSubmitted() && $uploadForm->isValid()) {
            $geojson = $uploadForm->getData();
            foreach ($geojson->features as $feature) {
                $zone = new Zone();
                $zone->setGeoJSON($feature['geometry']);
                $zoneCollection->zones[] = $zone;
            }
            $zoneCollectionForm->setData($zoneCollection);
        }

        $zones = $this->getDoctrine()->getRepository(Zone::class)->findAll();

        return [
            'zones' => $zones,
            'upload_form' => $uploadForm->createView(),
            'zone_collection_form' => $zoneCollectionForm->createView(),
        ];
    }

    /**
     * @Route("/admin/stores", name="admin_stores")
     * @Template
     */
    public function storesAction(Request $request)
    {
        $stores = $this->getDoctrine()->getRepository(Store::class)->findAll();

        return [
            'stores' => $stores
        ];
    }

    /**
     * @Route("/admin/stores/new", name="admin_store_new")
     * @Template("@App/Store/form.html.twig")
     */
    public function newStoreAction(Request $request)
    {
        $store = new Store();
        $form = $this->createForm(StoreType::class, $store, [
            'additional_properties' => $this->getLocalizedLocalBusinessProperties(),
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $store = $form->getData();
            $this->getDoctrine()->getManagerForClass(Store::class)->persist($store);
            $this->getDoctrine()->getManagerForClass(Store::class)->flush();

            $this->addFlash(
                'notice',
                $this->get('translator')->trans('Your changes were saved.')
            );

            return $this->redirectToRoute('admin_stores');
        }

        return [
            'store' => $store,
            'form' => $form->createView(),
        ];
    }
}
