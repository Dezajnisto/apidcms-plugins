<?php
/**
 * Плагин «Корзина» — CartController
 *
 * AJAX: /cart/add, /cart/remove, /cart/update
 * Страница: /cart
 */

namespace Plugins\Cart;

class Controller
{
    /**
     * Добавить товар в корзину
     * POST: { product_table, product_id, product_name, price, quantity }
     */
    public static function add($fc): array
    {
        $productTable = $_POST['product_table'] ?? 'products';
        $productId = (int)($_POST['product_id'] ?? 0);
        $productName = $_POST['product_name'] ?? 'Товар';
        $price = (float)($_POST['price'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));

        if ($productId <= 0) {
            return ['success' => false, 'error' => 'Не указан товар'];
        }

        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        $key = $productTable . '_' . $productId;
        $sessionId = $_SESSION['cart_session_id'];

        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$key] = [
                'product_table' => $productTable,
                'product_id' => $productId,
                'product_name' => $productName,
                'price' => $price,
                'quantity' => $quantity
            ];
        }

        // Синхронизируем с БД для залогиненных
        self::syncToDb($fc, $sessionId);

        return [
            'success' => true,
            'message' => "{$productName} добавлен в корзину",
            'count' => array_sum(array_column($_SESSION['cart'], 'quantity')),
            'total' => self::cartTotal()
        ];
    }

    /**
     * Удалить товар из корзины
     * POST: { product_table, product_id }
     */
    public static function remove($fc): array
    {
        $productTable = $_POST['product_table'] ?? 'products';
        $productId = (int)($_POST['product_id'] ?? 0);
        $key = $productTable . '_' . $productId;

        if (isset($_SESSION['cart'][$key])) {
            unset($_SESSION['cart'][$key]);
        }

        // Синхронизируем с БД
        $db = self::getDb($fc);
        $sessionId = $_SESSION['cart_session_id'];
        $db->query(
            "DELETE FROM plugin_cart_items WHERE session_id = ? AND product_table = ? AND product_id = ?",
            [$sessionId, $productTable, $productId]
        );

        return [
            'success' => true,
            'message' => 'Товар удалён из корзины',
            'count' => array_sum(array_column($_SESSION['cart'], 'quantity')),
            'total' => self::cartTotal()
        ];
    }

    /**
     * Изменить количество товара
     * POST: { product_table, product_id, quantity }
     */
    public static function update($fc): array
    {
        $productTable = $_POST['product_table'] ?? 'products';
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $key = $productTable . '_' . $productId;

        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['quantity'] = $quantity;
        }

        self::syncToDb($fc, $_SESSION['cart_session_id']);

        return [
            'success' => true,
            'count' => array_sum(array_column($_SESSION['cart'], 'quantity')),
            'total' => self::cartTotal(),
            'item_total' => ($_SESSION['cart'][$key]['price'] ?? 0) * $quantity
        ];
    }

    /**
     * Показать страницу корзины
     */
    public static function show($fc): void
    {
        $cartItems = $_SESSION['cart'] ?? [];
        $total = self::cartTotal();

        self::renderPlugin($fc, '@cart/cart.html.twig', [
            'title' => 'Корзина',
            'plugin_cart_items' => $cartItems,
            'total' => $total,
            'count' => count($cartItems)
        ]);
    }

    /**
     * Синхронизировать сессионную корзину с БД
     */
    private static function syncToDb($fc, string $sessionId): void
    {
        try {
            $db = self::getDb($fc);

            // Удаляем старые записи
            $db->query("DELETE FROM plugin_cart_items WHERE session_id = ?", [$sessionId]);

            // Вставляем текущие
            foreach ($_SESSION['cart'] ?? [] as $item) {
                $db->insert('plugin_cart_items', [
                    'user_id' => !empty($_SESSION['user_id']) ? $_SESSION['user_id'] : null,
                    'session_id' => $sessionId,
                    'product_table' => $item['product_table'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity']
                ]);
            }
        } catch (\Exception $e) {
            error_log("Cart sync error: " . $e->getMessage());
        }
    }

    private static function cartTotal(): float
    {
        $total = 0;
        foreach ($_SESSION['cart'] ?? [] as $item) {
            $total += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }
        return round($total, 2);
    }

    private static function getDb($fc)
    {
        $ref = new \ReflectionClass($fc);
        $prop = $ref->getProperty('database');
        $prop->setAccessible(true);
        return $prop->getValue($fc);
    }

    private static function renderPlugin($fc, $template, $data): void
    {
        $data['is_logged_in'] = !empty($_SESSION['user_id']);
        $data['current_user'] = !empty($_SESSION['user_id']) ? [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? '',
            'email' => $_SESSION['user_email'] ?? ''
        ] : null;

        $ref = new \ReflectionClass($fc);
        $method = $ref->getMethod('render');
        $method->setAccessible(true);
        $method->invoke($fc, $template, $data);
    }
}
