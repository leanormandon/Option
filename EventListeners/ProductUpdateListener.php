<?php

namespace Option\EventListeners;

use JsonException;
use Option\Service\OptionProductService;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Product\ProductAddCategoryEvent;
use Thelia\Core\Event\Product\ProductCloneEvent;
use Thelia\Core\Event\Product\ProductDeleteCategoryEvent;
use Thelia\Core\Event\Product\ProductSetTemplateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Base\CategoryQuery;
use Thelia\Model\Base\TemplateQuery;

class ProductUpdateListener implements EventSubscriberInterface
{
    private OptionProductService $optionProductService;

    public function __construct(OptionProductService $optionProductService)
    {
        $this->optionProductService = $optionProductService;
    }

    /**
     * @throws PropelException|JsonException
     */
    public function updateAddingCategoryOnProduct(ProductAddCategoryEvent $event): void
    {
        $product = $event->getProduct();
        $categoryId = $event->getCategoryId();
        $category = CategoryQuery::create()->findPk($categoryId);
        $categoryOptions = $category->getCategoryAvailableOptions();
        foreach ($categoryOptions as $categoryOption) {
            $this->optionProductService->setOptionOnProduct($product->getId(), $categoryOption->getOptionId(),
                OptionProductService::ADDED_BY_CATEGORY);
        }
    }

    /**
     * When deleting a category on a product, it also deletes the options linked to the product by that category.
     * Takes in consideration that an option can be added to the product by another of its categories.
     *
     * @throws PropelException
     */
    public function updateRemovingCategory(ProductDeleteCategoryEvent $event)
    {
        $product = $event->getProduct();
        $removedCategory = CategoryQuery::create()->findPk($event->getCategoryId());
        $remainingCategories = $product->getCategories();
        unset($remainingCategories[array_search($removedCategory, $remainingCategories->toArray(), true)]);

        $remainingCategoriesOptions = [];
        foreach ($remainingCategories as $remainingCategory) {
            $remainingCategoriesOptions[] = $remainingCategory->getCategoryAvailableOptions();
        }
        $remainingOptionIds = [];
        foreach ($remainingCategoriesOptions[0] as $remainingCategoriesOption) {
            if (!in_array($remainingCategoriesOption->getOptionId(), $remainingOptionIds, true)) {
                $remainingOptionIds[] = $remainingCategoriesOption->getOptionId();
            }
        }
        $removedCategoryOptions = $removedCategory->getCategoryAvailableOptions();
        foreach ($removedCategoryOptions as $categoryOption) {
            if (!in_array($categoryOption->getOptionId(), $remainingOptionIds, true)) {
                $this->optionProductService->deleteOptionOnProduct($categoryOption->getOptionId(), $product->getId(),
                    OptionProductService::ADDED_BY_CATEGORY);
            }
        }
    }

    /**
     * @throws PropelException|JsonException
     */
    public function updateWithTemplateOptions(ProductSetTemplateEvent $event): void
    {
        $product = $event->getProduct();
        $productOptions = $product->getProductAvailableOptions();

        if ($productOptions) {
            foreach ($productOptions as $productOption) {
                if (in_array(OptionProductService::ADDED_BY_TEMPLATE, $productOption->getOptionAddedBy(), true)) {
                    $this->optionProductService->deleteOptionOnProduct($productOption->getOptionId(), $product->getId
                    (), OptionProductService::ADDED_BY_TEMPLATE);
                }
            }
        }

        $template = TemplateQuery::create()->findPk($event->getTemplateId());
        if (!$template) {
            return;
        }

        $templateOptions = $template->getTemplateAvailableOptions();
        foreach ($templateOptions as $templateOption) {
            $this->optionProductService->setOptionOnProduct($product->getId(), $templateOption->getOptionId(), OptionProductService::ADDED_BY_TEMPLATE);
        }
    }

    public function cloneProductAvailableOption(ProductCloneEvent $event): void
    {
        $productAvailableOptions = $event->getOriginalProduct()->getProductAvailableOptions();

        if (!$productAvailableOptions->isEmpty()) {
            $event->getClonedProduct()->setProductAvailableOptions($productAvailableOptions)->save();
        }
    }


    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::PRODUCT_ADD_CATEGORY => ['updateAddingCategoryOnProduct', 50],
            TheliaEvents::PRODUCT_SET_TEMPLATE => ['updateWithTemplateOptions', 50],
            TheliaEvents::PRODUCT_REMOVE_CATEGORY => ['updateRemovingCategory', 50],
            TheliaEvents::PRODUCT_CLONE => ['cloneProductAvailableOption', 50]
        ];
    }
}