<?php

namespace App\Controller;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\Set;
use App\Service\ApiService;
use App\Service\ImageService;
use App\Form\Type\ImportDataType;
use App\Form\Type\ImportImageType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;


class ImportController extends AbstractController
{
    private string $uploadPath;
    private array $messages=[];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $importLogger,
        private readonly ImageService $imageService,
        private readonly ApiService $apiService
    ) {
        $this->uploadPath = $_SERVER['DOCUMENT_ROOT'] . '/uploaded/images';
    }

    #[Route('/api/v1/import_product_images', name: 'importProductImages', methods: ['POST'])]
    public function importProductImages(Request $request): JsonResponse
    {
        return $this->importImages($request, 'product');
    }    

    
    #[Route('/api/v1/import_variant_images', name: 'importVariantImages', methods: ['POST'])]
    public function importVariantImages(Request $request): JsonResponse
    {
        return $this->importImages($request, 'variant');
    }

    public function importImages(Request $request, $type='product'): JsonResponse
    {
        $uploadPath = $this->uploadPath . '/products';
        $this->messages = [];
        try {
            $form   = $this->createForm(ImportImageType::class);
            $data   = json_decode($request->getContent(), true);
            $form->submit($data);

            if ($form->isSubmitted() && $form->isValid()) {
                $product = $form->has('product') ? $form->get('product')?->getData() : null;

            if (!empty($product)) {
                if ($type=='product') {
                    $entity = $this->em->getRepository(Product::class)->findOneBy(['uid' => $product['id']]);

                    if (!$entity && $product['old_id']) {
                        $entity = $this->em->getRepository(Product::class)->findOneBy(['importId' => $product['old_id']]);
                        // echo 'found by old_id'.PHP_EOL;
                    } 
                } else {
                    $entity = $this->em->getRepository(ProductVariant::class)->findOneBy(['uid' => $product['id']]);

                    if (!$entity && $product['old_id']) {
                        $entity = $this->em->getRepository(ProductVariant::class)->findOneBy(['importId' => $product['old_id']]);
                        // echo 'found by old_id'.PHP_EOL;
                    }
                }

                    if ($entity) {
                        if (isset($product['images']) && sizeof($product['images'])) {
                            $imagesArray = [];
                            $imagesDelete = [];
                            $imagesOld = [];
                            $images_json = $entity->getImages();
                            if ($images_json) {
                                $json_array = json_decode($images_json, JSON_UNESCAPED_UNICODE);
                                foreach ($json_array as $json_item) {
                                    $imageDecoded = json_decode($json_item, JSON_UNESCAPED_UNICODE);
                                    if (!isset($imageDecoded['original']['date_modify'])) {
                                        $imagesDelete[] = $imageDecoded['original']['name'];
                                    } else {
                                        $imagesOld[] = [
                                            "title" => $imageDecoded['original']['title'],
                                            "date_modify" => $imageDecoded['original']['date_modify'],
                                            "name" => $imageDecoded['original']['name'],
                                            "json_data" => $json_item
                                        ];
                                    }
                                }
                            }

                        $images = $product['images'];

                            foreach ($images as $image) {
                                try {
                                    $original_filename =  $image['file_name'];
                                    $datemod = $image['date_modify'];
                                    $flagAdd = true;
                                    // Проверить наличие в текущих
                                    foreach ($imagesOld as $imageOld) {
                                        if ($original_filename == $imageOld['title']) {
                                            // Проверяем дату, формат даты строка 2024-01-01T01:01:01
                                            if ($datemod <= $imageOld['date_modify']) {
                                                $imagesArray[] = $imageOld['json_data'];
                                                $datemod = $imageOld['date_modify'];
                                                $flagAdd = false;
                                            } else {
                                                $imagesDelete[] = $imageOld["name"];
                                            }
                                        }
                                    }
                                    if ($flagAdd) {
                                        if (isset($image['base64'])) {
                                            $base64_string = $image['base64'];
                                            $name64 = time();

                                            $filename = sha1(uniqid($name64, true)) . '.jpg';
                                            $imgdata = base64_decode($base64_string);
                                            $sizes = $this->imageService->saveAllImages($uploadPath, $filename, $imgdata);
                                            $imagesArray[] = $this->imageService->getImageString($uploadPath, $original_filename, $filename, $sizes, $datemod);
                                        }
                                    } else {
                                        $message = "Изображение " . $original_filename . " от даты " . $datemod . " уже загружено";
                                        $this->addLog($message);
                                    }
                                } catch (\Exception $exception) {
                                    $message = $exception->getMessage();
                                    $this->addLog($message, 'error');
                                }
                            }
                            $entity->setImages(json_encode($imagesArray, JSON_UNESCAPED_UNICODE));
                            $this->em->flush();
                            $this->imageService->dropAllImages($uploadPath, $imagesDelete);
                            try {
                                $message = $type." ".$entity->getId().", ".$this->apiService->clearCache($entity->getId(), $type);
                                $this->addLog($message);
                            }  catch (\Exception $exception) {
                                $message = $exception->getMessage();
                                $this->addLog($message, 'error');
                            }
                            $message = sizeof($images) .' '. $type.' images ';
                            $this->addLog($message);
                        } else {
                            $message = 'images empty';
                            $this->addLog($message);
                        }
                    } else {
                        $message = 'entity not found';
                        $this->addLog($message);
                    }
                } else {
                    $message = 'product empty';
                    $this->addLog($message);
                }
                unset($images);
                return new JsonResponse(['success' => true, 'body' => $this->messages]);
            }
        } catch (\Exception $exception) {
            $message = $exception->getMessage();
            $this->addLog($message, 'error');
        }
        // Возвращаем ошибки валидации, если есть
        return new JsonResponse([
            'errors' => (string)$form->getErrors(true, false),
            'body' => $this->messages
        ], JsonResponse::HTTP_BAD_REQUEST);
    }

    #[Route('/api/v1/import', name: 'import', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $logger = $this->importLogger;
        $form   = $this->createForm(ImportDataType::class);
        $data   = json_decode($request->getContent(), true);
        $form->submit($data);
        $this->messages = [];

        if ($form->isSubmitted() && $form->isValid()) {
            $flagChange = false;
            $counter    = 0;
            $paket      = 20;
            $logger->info('start import');
            $logger->info(json_encode($data));

            $brands = $form->has('brands') ? $form->get('brands')?->getData() : null;
            $cacheBrands=[];
            if (!empty($brands)) {
                foreach ($brands as $brand) {
                    try {
                        $brandEntity = null;
                        $brandEntity = $this->em->getRepository(Brand::class)->findOneBy(['uid' => $brand['id']]);
                        if (!$brandEntity && $brand['old_id']) {
                            $brandEntity = $this->em->getRepository(Brand::class)->findOneBy(['importId' => $brand['old_id']]);
                            // echo 'found by old_id'.PHP_EOL;
                        }
                        if (!$brandEntity && $brand['name']) {
                            $brandEntity = $this->em->getRepository(Brand::class)->findOneBy(['title' => $brand['name']]);
                            // echo 'found by name'.PHP_EOL;
                            if ($brandEntity && $brandEntity->getUid()) {
                                $message = 'already exists brand with new code 1C import_id=' . $brandEntity->getImportId() . ' uid=' . $brandEntity->getUid();
                                $this->addLog($message, 'error');
                                $brandEntity = null;
                                continue;
                            }
                        }
                        if (!$brandEntity) {
                            $message = 'create new brand uid=' . $brand['id'];
                            $this->addLog($message);
                            $brandEntity = (new Brand())
                                ->setUid($brand['id'])
                                ->setImportId($brand['old_id'])
                                ->setTitle($brand['name'])
                                ->setEnabled(!$brand['deleted'])
                                ->setImported(true)
                                ->setImportStatus(true);
                            $this->em->persist($brandEntity);
                            $this->em->flush();
                            $counter    = 0;
                            $flagChange = false;
                        } else {
                            $message = 'found brandEntity id=' . $brandEntity->getId();
                            $this->addLog($message);
                            //echo $brandEntity->getUid().' '.$brandEntity->getImportId().' '.$brandEntity->getTitle().' '.$brandEntity->isEnabled().PHP_EOL;
                            //echo $brand['id'].' '.$brand['old_id'].' '.$brand['name'].' '.!$brand['deleted'].PHP_EOL;
                            if (!$brandEntity->getUid()) {
                                $brandEntity->setUid($brand['id']);
                                $flagChange = true;
                            }
                            if ($brandEntity->getTitle() !== $brand['name']) {
                                $brandEntity->setTitle($brand['name']);
                                $flagChange = true;
                            }
                            if ($brandEntity->isEnabled() !== !$brand['deleted']) {
                                $brandEntity->setEnabled(!$brand['deleted']);
                                $flagChange = true;
                            }
                            if ($flagChange) {
                                $cacheBrands[] = $brandEntity->getId();
                            }
                        }
                        $this->checkFlushPaket($counter, $flagChange, $paket);
                    } catch (\Exception $exception) {
                        $message = $exception->getMessage();
                        $this->addLog($message, 'error');
                    }
                }
                // если были изменения, то сделаем flush
                $this->checkFlushPaket($counter, $flagChange, 0);
                $message = sizeof($brands) . ' brands ';
                $this->addLog($message);
            } else {
                $message = 'brands empty';
                $this->addLog($message);
            }
            unset($brands);
            $this->cacheClearPaket($cacheBrands, 'brand');

            $categories = $form->has('categories') ? $form->get('categories')?->getData() : null;
            $cacheCategories = [];
            if (!empty($categories)) {
                foreach ($categories as $category) {
                    try {
                        $categoryEntity = $this->em->getRepository(Category::class)->findOneBy(['uid' => $category['id']]);
                        if (!$categoryEntity && $category['old_id']) {
                            $categoryEntity = $this->em->getRepository(Category::class)->findOneBy(['importId' => $category['old_id']]);
                            // echo 'found by old_id'.PHP_EOL;
                        }
                        if (!$categoryEntity && $category['name']) {
                            $categoryEntity = $this->em->getRepository(Category::class)->findOneBy(['title' => $category['name']]);
                            // echo 'found by name'.PHP_EOL;
                            if ($categoryEntity && $categoryEntity->getUid()) {
                                $message = 'already exists category with new code 1C import_id=' . $categoryEntity->getImportId() . ' uid=' . $categoryEntity->getUid();
                                $this->addLog($message, 'error');
                                $categoryEntity = null;
                                continue;
                            }
                        }
                        if (!$categoryEntity) {
                            $message = 'create new category uid=' . $category['id'] . ' name=' . $category['name'];
                            $this->addLog($message);
                            $categoryEntity = (new Category())
                                ->setUid($category['id'])
                                ->setImportId($category['old_id'])
                                ->setTitle($category['name'])
                                ->setEnabled(true)
                                ->setImported(true)
                                ->setImportStatus(true);
                            $this->em->persist($categoryEntity);
                            $this->em->flush();
                            $counter    = 0;
                            $flagChange = false;
                        } else {
                            $message = 'found categoryEntity id=' . $categoryEntity->getId();
                            $this->addLog($message);
                            if (!$categoryEntity->getUid()) {
                                $categoryEntity->setUid($category['id']);
                                $flagChange = true;
                            }
                            if ($categoryEntity->getTitle() !== $category['name']) {
                                $categoryEntity->setTitle($category['name']);
                                $flagChange = true;
                            }
                            if ($categoryEntity->isEnabled() !== !$category['deleted']) {
                                $categoryEntity->setEnabled(!$category['deleted']);
                                $flagChange = true;
                            }
                            if ($flagChange) {
                                $cacheCategories[] = $categoryEntity->getId();
                            }
                        }
                        $this->checkFlushPaket($counter, $flagChange, $paket);
                    } catch (\Exception $exception) {
                        $message = $exception->getMessage();
                        $this->addLog($message, 'error');
                    }
                }
                // если были изменения, то сделаем flush
                $this->checkFlushPaket($counter, $flagChange, 0);
                $message = sizeof($categories) . ' categories ';
                $this->addLog($message);
            } else {
                $message = 'categories empty';
                $this->addLog($message);
            }
            unset($categories);
            $this->cacheClearPaket($cacheCategories, 'category');

            $sets = $form->has('sets') ? $form->get('sets')->getData() : null;
            $cacheSets = [];
            if (!empty($sets)) {
                foreach ($sets as $set) {
                    try {
                        $setEntity = $this->em->getRepository(Set::class)->findOneBy(['uid' => $set['id']]);
                        if (!$setEntity && $set['old_id']) {
                            $setEntity = $this->em->getRepository(Set::class)->findOneBy(['importId' => $set['old_id']]);
                            // echo 'found by old_id'.PHP_EOL;
                        }
                        if (!$setEntity && $set['name']) {
                            $setEntity = $this->em->getRepository(Set::class)->findOneBy(['title' => $set['name']]);
                            // echo 'found by name'.PHP_EOL;
                            if ($setEntity && $setEntity->getUid()) {
                                $message = 'already exists set with new code 1C import_id=' . $setEntity->getImportId() . ' uid=' . $setEntity->getUid();
                                $this->addLog($message);
                                $setEntity = null;
                                continue;
                            }
                        }
                        if (!$setEntity) {
                            if ($set['name']) {
                                $message = 'create new set uid=' . $set['id'];
                                $this->addLog($message);
                                $setEntity = (new Set())
                                    ->setUid($set['id'])
                                    ->setImportId($set['old_id'])
                                    ->setTitle($set['name'])
                                    ->setEnabled(true)
                                    ->setImported(true)
                                    ->setImportStatus(true);
                                $this->em->persist($setEntity);
                                $this->em->flush();
                                $counter    = 0;
                                $flagChange = false;
                            } else {
                                $message = 'Can not create set uid=' . $set['id'];
                                throw new \Exception($message);
                            }
                        } else {
                            $message = 'found setEntity id=' . $setEntity->getId();
                            $this->addLog($message);
                            if (!$setEntity->getUid()) {
                                $setEntity->setUid($set['id']);
                                $flagChange = true;
                            }
                            if ($setEntity->getTitle() !== $set['name']) {
                                $setEntity->setTitle($set['name']);
                                $flagChange = true;
                            }
                            if ($setEntity->isEnabled() !== !$set['deleted']) {
                                $setEntity->setEnabled(!$set['deleted']);
                                $flagChange = true;
                            }
                            if ($flagChange) {
                                $cacheSets[] = $setEntity->getId();
                            }
                        }
                        $this->checkFlushPaket($counter, $flagChange, $paket);
                    } catch (\Exception $exception) {
                        $message = $exception->getMessage();
                        $this->addLog($message, 'error');
                    }
                }
                // если были изменения, то сделаем flush
                $this->checkFlushPaket($counter, $flagChange, 0);
                $message = sizeof($sets) . ' sets';
                $this->addLog($message);
            } else {
                $message = 'sets empty';
                $this->addLog($message);
            }
            unset($sets);
            $this->cacheClearPaket($cacheSets, 'set');

            $products = $form->has('products') ? $form->get('products')?->getData() : null;
            $cacheProducts = [];
            $cacheVariants = [];
            if (!empty($products)) {
                foreach ($products as $product) {
                    try {
                        $category = $this->em->getRepository(Category::class)->findOneBy(['uid' => $product['category_id']]);
                        if (!$category) {
                            $message = 'Category not found for category_id=' . $product['category_id'] . ' product uid=' . $product['id'] . ' product_name=' . $product['name'];
                            throw new \Exception($message);
                        }
                        $brand = $this->em->getRepository(Brand::class)->findOneBy(['uid' => $product['brand']]);
                        if (!$brand) {
                            $message = 'Category not found for brand=' . $product['brand'] . ' product uid=' . $product['uid'] . ' product_name=' . $product['name'];
                            throw new \Exception($message);
                        }

                        $toBuys = [];
                        if (isset($product['to_buy']) && sizeof($product['to_buy'])) {
                            foreach ($product['to_buy'] as $toBuyId) {
                                $productToBuy = $this->em->getRepository(Product::class)->findOneBy(['uid' => $toBuyId['id']]);
                                if (!$productToBuy) {
                                    $message = 'Product to buy not found for to_buy_id=' . $toBuyId['id'] . ' product uid=' . $product['id'] . ' product_name=' . $product['name'];
                                    $this->addLog($message, 'error');
                                } else {
                                    $toBuys[] = $productToBuy->getId();
                                }
                            }
                        }

                        $sets = [];
                        if (isset($product['sets']) && sizeof($product['sets'])) {
                            foreach ($product['sets'] as $setImportId) {
                                $set = $this->em->getRepository(Set::class)->findOneBy(['uid' => $setImportId['id']]);
                                if (!$set) {
                                    $message = 'Set for product not found for set_import_id=' . $setImportId['id'] . ' product uid=' . $product['id'] . ' product_name=' . $product['name'];
                                    $this->addLog($message, 'error');
                                } else {
                                    $sets[] = $set;
                                }
                            }
                        }

                        $enabled   = !$product['deleted'];
                        $title     = $product['name'];
                        $price     = $product['price'];
                        $sale      = $product['discount'] ?? false;
                        $priceSale = $product['discount_price'] ?? $price;
                        if ($priceSale < $price) {
                            $sale = true;
                        }
                        $isEating = $product['is_eating'] ?? null;

                        $countMayer = $product['remains']['shopMayer'] ?? 0;
                        $countMolok = $product['remains']['shopOptima'] ?? 0;
                        $countYarig = $product['remains']['skladMayer'] ?? 0;
                        $countLado  = $product['remains']['marketplace'] ?? 0;

                        $inStock = $countMayer && $countMolok;

                        $productEntity = $this->em->getRepository(Product::class)->findOneBy(['uid' => $product['id']]);

                        if (!$productEntity && $product['old_id']) {
                            $productEntity = $this->em->getRepository(Product::class)->findOneBy(['importId' => $product['old_id']]);
                        }
                        if (!$productEntity && $title) {
                            $productEntity = $this->em->getRepository(Product::class)->findOneBy(['title' => $title]);
                            if ($productEntity && $productEntity->getUid()) {
                                $message = 'already exists product with new code 1C uid=' . $productEntity->getUid();
                                $this->addLog($message, 'error');
                                $productEntity = null;
                                continue;
                            }
                        }
                        if (!$productEntity) {
                            $message = 'create new product uid=' . $product['id'];
                            $this->addLog($message);
                            $productEntity = (new Product())
                                ->setUid($product['id'])
                                ->setImportId($product['old_id'])
                                ->setCategory($category)
                                ->setBrand($brand)
                                ->setTitle($title)
                                ->setEnabled($enabled)
                                ->setImported(true)
                                ->setImportStatus(true)
                                ->setPrice($price)
                                ->setPriceSale($priceSale)
                                ->setSale($sale)
                                ->setEatable($isEating)
                                ->setCountMayer($countMayer)
                                ->setCountMolok($countMolok)
                                ->setCountYarig($countYarig)
                                ->setCountLado($countLado)
                                ->setInStock($inStock);
                            $this->em->persist($productEntity);
                            $this->em->flush();
                            $counter    = 0;
                            $flagChange = false;
                        } else {
                            $message = 'found productEntity id=' . $productEntity->getId();
                            $this->addLog($message);
                            if (!$productEntity->getUid()) {
                                $productEntity->setUid($product['id']);
                                $flagChange = true;
                            }
                            if ($productEntity->getTitle() !== $title) {
                                $productEntity->setTitle($title);
                                $flagChange = true;
                            }
                            if ($productEntity->isEnabled() !== $enabled) {
                                $productEntity->setEnabled($enabled);
                                $flagChange = true;
                            }
                            if ($productEntity->getCategory() !== $category) {
                                $productEntity->setCategory($category);
                                $flagChange = true;
                            }
                            if ($productEntity->getBrand() !== $brand) {
                                $productEntity->setBrand($brand);
                                $flagChange = true;
                            }
                            if ($productEntity->getPrice() != $price) {
                                $productEntity->setPrice($price);
                                $flagChange = true;
                            }
                            if ($productEntity->isSale() != $sale) {
                                $productEntity->setSale($sale);
                                $flagChange = true;
                            }
                            if ($productEntity->getPriceSale() != $priceSale) {
                                $productEntity->setPriceSale($priceSale);
                                $flagChange = true;
                            }
                            if ($isEating !== null && ($productEntity->isEatable() != $isEating)) {
                                $productEntity->setEatable($isEating);
                                $flagChange = true;
                            }
                            if ($productEntity->getCountMayer() != $countMayer) {
                                $productEntity->setCountMayer($countMayer);
                                $flagChange = true;
                            }
                            if ($productEntity->getCountMolok() != $countMolok) {
                                $productEntity->setCountMolok($countMolok);
                                $flagChange = true;
                            }
                            if ($productEntity->getCountYarig() != $countYarig) {
                                $productEntity->setCountYarig($countYarig);
                                $flagChange = true;
                            }
                            if ($productEntity->getCountLado() != $countLado) {
                                $productEntity->setCountLado($countLado);
                                $flagChange = true;
                            }
                            if ($productEntity->isInStock() != $inStock) {
                                $productEntity->setInStock($inStock);
                            }
                        }

                        if (count($toBuys)) {
                            if ($productEntity->getToBuy() != json_encode($toBuys)) {
                                $productEntity->setToBuy(json_encode($toBuys));
                                $flagChange = true;
                            }
                        }

                        if (count($sets)) {
                            // добавляем новые подборки
                            foreach ($sets as $setEntity) {
                                if (!$productEntity->getSets()->contains($setEntity)) {
                                    $productEntity->addSet($setEntity);
                                    $flagChange = true;
                                }
                            }
                            // удаляем старые
                            foreach ($productEntity->getSets() as $setProduct) {
                                if (!in_array($setProduct, $sets)) {
                                    $productEntity->removeSet($setProduct);
                                    $flagChange = true;
                                }
                            }
                        }

                        if (isset($product['variants']) && sizeof($product['variants'])) {
                            foreach ($product['variants'] as $variant) {
                                try {
                                    $variantUid       = $variant['id'];
                                    $variantImportId  = $variant['old_id'];
                                    $barcode          = $variant['ean13'] ?? null;
                                    $flavor           = $variant['flavor'] ?? null;
                                    $volume           = $variant['volume'] ?? null;
                                    $variantPrice     = $variant['price'];
                                    $variantSale      = $variant['discount'] ?? false;
                                    $variantPriceSale = $variant['discount_price'] ?? $variantPrice;
                                    if ($variantSale < $variantPrice) {
                                        $variantSale = true;
                                    }
                                    $variantPriceMp    = $variant['price_mp'] ?? null;
                                    $variantCountMayer = $variant['remains']['shopMayer'] ?? 0;
                                    $variantCountMolok = $variant['remains']['shopOptima'] ?? 0;
                                    $variantCountYarig = $variant['remains']['skladMayer'] ?? 0;
                                    $variantCountLado  = $variant['remains']['marketplace'] ?? 0;

                                    $variantEntity = $this->em->getRepository(ProductVariant::class)->findOneBy(['uid' => $variantUid]);
                                    if (!$variantEntity) {
                                        $variantEntity = $this->em->getRepository(ProductVariant::class)->findOneBy(['importId' => $variantImportId]);
                                    }

                                    if (!$variantEntity) {
                                        $message = 'create new variant uid=' . $variant['id'];
                                        $this->addLog($message);
                                        $variantEntity = (new ProductVariant())
                                            ->setUid($variantUid)
                                            ->setImportId($variantImportId)
                                            ->setProduct($productEntity)
                                            ->setBarcode($barcode)
                                            ->setFlavor($flavor)
                                            ->setVolume($volume)
                                            ->setPrice($variantPrice)
                                            ->setPriceSale($variantPriceSale)
                                            ->setSale($variantSale)
                                            ->setPriceMp($variantPriceMp)
                                            ->setCountMayer($variantCountMayer)
                                            ->setCountMolok($variantCountMolok)
                                            ->setCountYarig($variantCountYarig)
                                            ->setCountLado($variantCountLado);
                                        $this->em->persist($variantEntity);
                                        $this->em->flush();
                                        $counter    = 0;
                                        $flagChange = false;
                                    } else {
                                        $message = 'found variantEntity id=' . $variantEntity->getId();
                                        $this->addLog($message);
                                        if (!$variantEntity->getUid()) {
                                            $variantEntity->setUid($variantUid);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getBarcode() !== $barcode) {
                                            $variantEntity->setBarcode($barcode);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getFlavor() !== $flavor) {
                                            $variantEntity->setFlavor($flavor);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getVolume() !== $volume) {
                                            $variantEntity->setVolume($volume);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getPrice() !== $variantPrice) {
                                            $variantEntity->setPrice($variantPrice);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getPriceSale() !== $variantPriceSale) {
                                            $variantEntity->setPriceSale($variantPriceSale);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->isSale() !== $variantSale) {
                                            $variantEntity->setSale($variantSale);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getPriceMp() !== $variantPriceMp) {
                                            $variantEntity->setPriceMp($variantPriceMp);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getCountMayer() !== $variantCountMayer) {
                                            $variantEntity->setCountMayer($variantCountMayer);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getCountMolok() !== $variantCountMolok) {
                                            $variantEntity->setCountMolok($variantCountMolok);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getCountYarig() !== $variantCountYarig) {
                                            $variantEntity->setCountYarig($variantCountYarig);
                                            $flagChange = true;
                                        }
                                        if ($variantEntity->getCountLado() !== $variantCountLado) {
                                            $variantEntity->setCountLado($variantCountLado);
                                            $flagChange = true;
                                        }
                                        if ($flagChange) {
                                            $cacheVariants[] = $variantEntity->getId();
                                        }
                                    }
                                    $this->checkFlushPaket($counter, $flagChange, $paket);
                                } catch (\Exception $exception) {
                                    $message = 'Exception variant for product uid=' . $product['id'] . ' product_name=' . $product['name'] . ' error=' . $exception->getMessage();
                                    $this->addLog($message, 'error');
                                }
                            }
                        }

                        if ($flagChange) {
                            $cacheProducts[] = $productEntity->getId();
                        }

                        $this->checkFlushPaket($counter, $flagChange, $paket);
                    } catch (\Exception $exception) {
                        $message = $exception->getMessage();
                        $this->addLog($message, 'error');
                    }
                }
                // если были изменения, то сделаем flush
                $this->checkFlushPaket($counter, $flagChange, 0);
                $message = sizeof($products) . ' products';
                $this->addLog($message);
            } else {
                $message = 'products empty';
                $this->addLog($message);
            }
            unset($products);
            $this->cacheClearPaket($cacheProducts, 'product');
            $this->cacheClearPaket($cacheVariants, 'variant');

            return new JsonResponse(['success' => true, 'body' => $this->messages]);
        }

        // Возвращаем ошибки валидации, если есть
        return new JsonResponse([
            'errors' => (string)$form->getErrors(true, false),
            'body' => $this->messages
        ], JsonResponse::HTTP_BAD_REQUEST);
    }

    #[Route('/', name: 'index')]
    public function index(Request $request): JsonResponse
    {
        $id      = 3;
        $product = $this->em->getRepository(Product::class)->find($id);

        return new JsonResponse(['product' => serialize($product)]);
    }

    private function addLog(string $message, string $type = 'info'): void
    {   if ($type == 'error') {
            $this->messages[] = ['message' => $message, 'type' => $type];
        }
        $this->importLogger->log($type == 'info' ? LogLevel::INFO : LogLevel::ERROR, $message);
    }

    private function cacheClearPaket(array &$array, string $type) 
    {   
        foreach ($array as $item)
        try {
            $message = $type." ".$item.", ".$this->apiService->clearCache($item, $type);
            $this->addLog($message);
        }  catch (\Exception $exception) {
            $message = $exception->getMessage();
            $this->addLog($message,'error');
        }
        unset($array);
    }

    private function checkFlushPaket(int &$counter, bool &$flagChange, int $paket): void
    {
        if ($flagChange) {
            if ($counter >= $paket) {
                $this->em->flush();
                $counter    = 0;
                $flagChange = false;
            } else {
                $counter++;
            }
        }
    }
}
