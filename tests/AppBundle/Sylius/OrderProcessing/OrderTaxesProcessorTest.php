<?php

namespace Tests\AppBundle\Sylius\OrderProcessing;

use AppBundle\Entity\Sylius\Order;
use AppBundle\Entity\Sylius\OrderItem;
use AppBundle\Entity\Sylius\TaxRate;
use AppBundle\Service\SettingsManager;
use AppBundle\Sylius\Order\AdjustmentInterface;
use AppBundle\Sylius\Order\OrderItemInterface;
use AppBundle\Sylius\OrderProcessing\OrderTaxesProcessor;
use AppBundle\Sylius\Product\ProductVariantInterface;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Taxation\Model\TaxCategory;
use Sylius\Component\Taxation\Repository\TaxCategoryRepositoryInterface;
use Sylius\Component\Taxation\Resolver\TaxRateResolver;
use Sylius\Component\Order\Model\Adjustment;
use Sylius\Component\Order\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OrderTaxesProcessorTest extends KernelTestCase
{
    use ProphecyTrait;

    private $settingsManager;
    private $taxCategoryRepository;
    private $orderTaxesProcessor;
    private $taxCategory;

    public function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $this->settingsManager = $this->prophesize(SettingsManager::class);
        $this->taxCategoryRepository = $this->prophesize(TaxCategoryRepositoryInterface::class);
        $this->taxRateRepository = $this->prophesize(RepositoryInterface::class);

        $adjustmentFactory = static::$kernel->getContainer()->get('sylius.factory.adjustment');
        $calculator = static::$kernel->getContainer()->get('sylius.tax_calculator');
        $this->orderItemUnitFactory = static::$kernel->getContainer()->get('sylius.factory.order_item_unit');

        $taxRate20 = new TaxRate();
        $taxRate20->setName('TVA livraison');
        $taxRate20->setAmount(0.2);
        $taxRate20->setCalculator('default');
        $taxRate20->setIncludedInPrice(true);

        $taxCategory = new TaxCategory();
        $taxCategory->addRate($taxRate20);

        $this->settingsManager
            ->get('default_tax_category')
            ->willReturn('tva_livraison');

        $this->taxCategoryRepository
            ->findOneBy(['code' => 'tva_livraison'])
            ->willReturn($taxCategory);

        $this->taxRate10 = new TaxRate();
        $this->taxRate10->setName('TVA conso immédiate');
        $this->taxRate10->setAmount(0.1);
        $this->taxRate10->setCalculator('default');
        $this->taxRate10->setIncludedInPrice(true);

        $this->taxCategory = new TaxCategory();
        $this->taxCategory->addRate($this->taxRate10);

        $taxRateResolver = new TaxRateResolver(
            $this->taxRateRepository->reveal()
        );

        $this->orderTaxesProcessor = new OrderTaxesProcessor(
            $adjustmentFactory,
            $taxRateResolver,
            $calculator,
            $this->settingsManager->reveal(),
            $this->taxCategoryRepository->reveal(),
            static::$kernel->getContainer()->get('translator')
        );
    }

    private function createOrderItem($unitPrice, $quantity = 1, TaxCategory $taxCategory = null)
    {
        $productVariant = $this->prophesize(ProductVariantInterface::class);
        $productVariant
            ->getTaxCategory()
            ->willReturn($taxCategory ?? $this->taxCategory);

        $orderItem = new OrderItem();
        $orderItem->setVariant($productVariant->reveal());
        $orderItem->setUnitPrice($unitPrice);

        for ($i = 0; $i < $quantity; ++$i) {
            $this->orderItemUnitFactory->createForItem($orderItem);
        }

        return $orderItem;
    }

    public function testEmptyOrder()
    {
        $order = new Order();

        $this->orderTaxesProcessor->process($order);

        $adjustments = $order->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);

        $this->assertCount(0, $adjustments);
        $this->assertEquals(0, $order->getTaxTotal());
    }

    public function testOrderWithoutDelivery()
    {
        $this->taxRateRepository
            ->findOneBy(Argument::type('array'))
            ->willReturn($this->taxRate10);

        $order = new Order();
        $order->addItem($this->createOrderItem(1000));

        $this->assertEquals(1000, $order->getTotal());

        $this->orderTaxesProcessor->process($order);

        $adjustments = $order->getAdjustmentsRecursively(AdjustmentInterface::TAX_ADJUSTMENT);

        // Incl. tax = 1000
        // Tax total = (1000 - (1000 / (1 + 0.1))) = 91
        // Excl. tax = 909
        $this->assertCount(1, $adjustments);
        $this->assertEquals(91, $order->getTaxTotal());
    }

    public function testOrderWithDelivery()
    {
        $this->taxRateRepository
            ->findOneBy(Argument::type('array'))
            ->willReturn($this->taxRate10);

        $deliveryAdjustment = new Adjustment();
        $deliveryAdjustment->setType(AdjustmentInterface::DELIVERY_ADJUSTMENT);
        $deliveryAdjustment->setAmount(350);
        $deliveryAdjustment->setNeutral(false);

        $order = new Order();
        $order->addItem($this->createOrderItem(1000));
        $order->addAdjustment($deliveryAdjustment);

        $this->assertEquals(1350, $order->getTotal());

        $this->orderTaxesProcessor->process($order);

        // Incl. tax (items) = 1000
        // Tax total (items) = (1000 - (1000 / (1 + 0.1))) = 91
        // Incl. tax (delivery) = 350
        // Tax total (delivery) = (350 - (350 / (1 + 0.2))) = 58

        // Tax total (items + delivery) = 91 + 58 = 149
        $this->assertEquals(149, $order->getTaxTotal());

        $adjustments = $order->getAdjustmentsRecursively(AdjustmentInterface::TAX_ADJUSTMENT);
        $this->assertCount(2, $adjustments);

        $adjustments = $order->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);
        $this->assertCount(1, $adjustments);

        // Incl. tax = 350
        // Tax total = (350 - (350 / (1 + 0.2))) = 58
        // Excl. tax = 292
        $this->assertEquals(58, $adjustments->first()->getAmount());
    }

    public function testOrderWithGstPst()
    {
        $gst = new TaxRate();
        $gst->setName('GST');
        $gst->setAmount(0.05);
        $gst->setCalculator('default');
        $gst->setIncludedInPrice(false);
        $gst->setCountry('ca-bc');

        $pst = new TaxRate();
        $pst->setName('PST');
        $pst->setAmount(0.07);
        $pst->setCalculator('default');
        $pst->setIncludedInPrice(false);
        $pst->setCountry('ca-bc');

        $taxCategory = new TaxCategory();
        $taxCategory->setCode('GST_PST');
        $taxCategory->addRate($gst);
        $taxCategory->addRate($pst);

        $order = new Order();
        $order->addItem($this->createOrderItem(1000, 1, $taxCategory));

        $this->assertEquals(1000, $order->getTotal());

        $this->orderTaxesProcessor->process($order);

        $this->assertEquals(1120, $order->getTotal());

        // Excl. tax (items) = 1000
        // Tax total (items) = (1000 * 0.05) + (1000 * 0.07) = 120
        $this->assertEquals(120, $order->getTaxTotal());

        $adjustments = $order->getAdjustmentsRecursively(AdjustmentInterface::TAX_ADJUSTMENT);
        $this->assertCount(2, $adjustments);

        $amounts = array_map(fn($adj) => $adj->getAmount(), $adjustments->toArray());

        $this->assertContains(50, $amounts);
        $this->assertContains(70, $amounts);
    }
}
