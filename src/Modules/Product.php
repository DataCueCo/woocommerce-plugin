<?php

namespace DataCue\WooCommerce\Modules;

/**
 * Class Product
 * @package DataCue\WooCommerce\Modules
 */
class Product extends Base
{
    /**
     * Generate product item for DataCue
     * @param $id int Product ID
     * @param $withId bool
     * @param $isVariant bool
     * @return array|null
     */
    public static function generateProductItem($id, $withId = false, $isVariant = false)
    {
        if (is_string($id) || is_int($id)) {
            $product = wc_get_product($id);
            if (empty($product)) {
                return null;
            }
        } else {
            $product = $id;
        }

        if ($isVariant) {
            $parentProduct = wc_get_product($product->get_parent_id());
        } else {
            $parentProduct = null;
        }

        // generate product item for DataCue
        $item = [
            'name' => $isVariant ? $parentProduct->get_name() : $product->get_name(),
            'price' => $product->get_sale_price() ? (float)$product->get_sale_price() : (float)$product->get_regular_price(),
            'full_price' => (float)$product->get_regular_price(),
            'link' => get_permalink($product->get_id()),
            'available' => $product->get_status() === 'publish',
            'description' => $isVariant ? $parentProduct->get_description() : $product->get_description(),
            'brand' => $isVariant ? static::getFirstBrandNameByProductId($product->get_parent_id()) : static::getFirstBrandNameByProductId($product->get_id()),
        ];

        // get photo url
        $imageId = $isVariant ? $parentProduct->get_image_id() : $product->get_image_id();
        if ($imageId) {
            $item['photo_url'] = wp_get_attachment_image_url($imageId);
        } else {
            $item['photo_url'] = wc_placeholder_img_src();
        }

        // get stock
        $stock = $product->get_stock_quantity();
        if (!is_null($stock)) {
            $item['stock'] = $stock;
        }

        // get categories
        if ($isVariant) {
            $item['category_ids'] = array_map(function ($item) {
                return "$item";
            }, $parentProduct->get_category_ids());
        } else {
            $item['category_ids'] = array_map(function ($item) {
                return "$item";
            }, $product->get_category_ids());
        }

        if ($withId) {
            if ($isVariant) {
                $item['product_id'] = '' . $product->get_parent_id();
                $item['variant_id'] = '' . $product->get_id();
            } else {
                $item['product_id'] = '' . $product->get_id();
                $item['variant_id'] = 'no-variants';
            }
        }

        return $item;
    }

    /**
     * Get parent id of product
     * @param $id
     * @return int
     */
    public static function getParentProductId($id)
    {
        $product = wc_get_product($id);
        return $product->get_parent_id();
    }

    /**
     * @param $id
     * @return null|string
     */
    public static function getFirstBrandNameByProductId($id)
    {
        global $wpdb;
        $sql = "SELECT c.`name` FROM `wp_term_relationships` a 
                LEFT JOIN `wp_term_taxonomy` b ON a.`term_taxonomy_id` = b.`term_taxonomy_id` 
                LEFT JOIN `wp_terms` c ON c.`term_id` = b.`term_id` 
                WHERE a.`object_id` = %d AND b.`taxonomy` = %s";
        $item = $wpdb->get_row(
            $wpdb->prepare($sql, intval($id), 'product_brand')
        );

        if (is_null($item)) {
            return null;
        }

        return $item->name;
    }

    private $oldProductType = null;

    private $isOnProductUpdatedFunctionFired = false;

    /**
     * Product constructor.
     * @param $client
     * @param array $options
     */
    public function __construct($client, array $options = [])
    {
        parent::__construct($client, $options);

        add_action('transition_post_status', [$this, 'onProductStatusChanged'], 10, 3);
        add_action('pre_post_update', [$this, 'beforeProductUpdated'], 10, 2);
        add_action('woocommerce_update_product', [$this, 'onProductUpdated']);
        add_action('woocommerce_update_product_variation', [$this, 'onVariantUpdated']);
        add_action('before_delete_post', [$this, 'onVariantDeleted']);
    }

    /**
     * Product status changed callback
     * @param $newStatus
     * @param $oldStatus
     * @param \WP_Post $post
     */
    public function onProductStatusChanged($newStatus, $oldStatus, $post) {
        if ($post->post_type !== 'product' && $post->post_type !== 'product_variation') {
            return;
        }

        $this->log('onProductStatusChanged new_status=' . $newStatus . ' && old_status=' . $oldStatus);

        $id = $post->ID;

        if ($newStatus === 'publish' && $oldStatus !== 'publish') {
            if ($post->post_type === 'product') {
                $product = wc_get_product($id);
                if ($product->get_type() !== 'variable') {
                    $this->log('Create product');
                    $this->log("product_id=$id");
                    $item = static::generateProductItem($product, true);
                    $this->addTaskToQueue('products', 'create', $id, ['item' => $item]);
                }
            } else {
                $this->log('Create variant');
                $this->log("variant_id=$id");
                $item = static::generateProductItem($id, true, true);
                $this->addTaskToQueue('products', 'create', $id, ['item' => $item]);
            }
            return;
        }

        if ($oldStatus === 'publish' && $newStatus !== 'publish') {
            if ($post->post_type === 'product') {
                $product = wc_get_product($id);
                if ($product->get_type() !== 'variable') {
                    $this->log('Delete product');
                    $this->log("product_id=$id");
                    $this->addTaskToQueue('products', 'delete', $id, ['productId' => $id, 'variantId' => 'no-variants']);
                }
            } else {
                $this->log('Delete variant');
                $this->log("variant_id=$id");
                $product = wc_get_product($id);
                $this->addTaskToQueue('products', 'delete', $id, ['productId' => $product->get_parent_id(), 'variantId' => $id]);
            }
            return;
        }
    }

    public function beforeProductUpdated($id, array $data)
    {
        $post = get_post($id);
        if ($post->post_type === 'product') {
            $product = wc_get_product($post);
            $this->oldProductType = $product->get_type();
        }
    }

    /**
     * Product updated callback
     * @param $id
     */
    public function onProductUpdated($id)
    {
        // this hook will be fired twice. So we need to filter out the second time.
        if ($this->isOnProductUpdatedFunctionFired) {
            return;
        }
        $this->isOnProductUpdatedFunctionFired = true;

        $product = wc_get_product($id);
        if ($product->get_status() !== 'publish') {
            return;
        }

        if ($product->get_type() === 'variable' && $this->oldProductType !== 'variable') {
            $this->log('Delete product (product type changed to variable)');
            $this->log("product_id=$id");
            $this->addTaskToQueue('products', 'delete', $id, ['productId' => $id, 'variantId' => 'no-variants']);
            return;
        }

        if ($product->get_type() !== 'variable' && $this->oldProductType === 'variable') {
            $this->log('Create product (product type changed to non-variable)');
            $this->log("product_id=$id");
            $item = static::generateProductItem($product, true);
            $this->addTaskToQueue('products', 'create', $id, ['item' => $item]);

            // update variants belonging the current product (For a special scene: mundopetit.cl)
            $variants = $product->get_children();
            foreach ($variants as $variantId) {
                $this->onVariantUpdated($variantId);
            }
            return;
        }

        if ($product->get_type() !== 'variable') {
            $this->log('Update product');
            $this->log("product_id=$id");

            if ($task = $this->findAliveTask('products', 'create', $id)) {
                $item = static::generateProductItem($id, true);
                $this->updateTask($task->id, ['item' => $item]);
            } elseif ($task = $this->findAliveTask('products', 'update', $id)) {
                $item = static::generateProductItem($id);
                $this->updateTask($task->id, ['productId' => $id, 'variantId' => 'no-variants', 'item' => $item]);
            } else {
                $item = static::generateProductItem($id);
                $this->addTaskToQueue('products', 'update', $id, ['productId' => $id, 'variantId' => 'no-variants', 'item' => $item]);
            }
        }

        // update variants belonging the current product
        $variants = $product->get_children();
        foreach ($variants as $variantId) {
            $this->onVariantUpdated($variantId);
        }
    }

    /**
     * Variant updated callback
     * @param $id
     */
    public function onVariantUpdated($id)
    {
        $product = wc_get_product($id);
        if ($product->get_status() !== 'publish') {
            return;
        }

        $this->log('Update variant');
        $this->log("variant_id=$id");

        if ($task = $this->findAliveTask('products', 'create', $id)) {
            $item = static::generateProductItem($id, true, true);
            $this->updateTask($task->id, ['item' => $item]);
        } elseif ($task = $this->findAliveTask('products', 'update', $id)) {
            $item = static::generateProductItem($id, false, true);
            $this->updateTask($task->id, ['productId' => static::getParentProductId($id), 'variantId' => $id, 'item' => $item]);
        } else {
            $item = static::generateProductItem($id, false, true);
            $this->addTaskToQueue('products', 'update', $id, ['productId' => static::getParentProductId($id), 'variantId' => $id, 'item' => $item]);
        }
    }

    /**
     * Variant deleted callback
     * @param $id
     */
    public function onVariantDeleted($id)
    {
        $post = get_post($id);
        if ($post->post_type === 'product_variation') {
            $this->log('Delete variant');
            $this->log("variant_id=$id");
            $product = wc_get_product($id);
            $this->addTaskToQueue('products', 'delete', $id, ['productId' => $product->get_parent_id(), 'variantId' => $id]);
        }
    }
}
