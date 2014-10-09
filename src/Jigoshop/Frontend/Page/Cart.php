<?php

namespace Jigoshop\Frontend\Page;

use Jigoshop\Core\Messages;
use Jigoshop\Core\Options;
use Jigoshop\Core\Pages;
use Jigoshop\Core\Types;
use Jigoshop\Exception;
use Jigoshop\Frontend\Page;
use Jigoshop\Helper\Country;
use Jigoshop\Helper\Product;
use Jigoshop\Helper\Render;
use Jigoshop\Helper\Scripts;
use Jigoshop\Helper\Styles;
use Jigoshop\Service\CartServiceInterface;
use Jigoshop\Service\Customer;
use Jigoshop\Service\ProductServiceInterface;
use Jigoshop\Service\ShippingServiceInterface;
use WPAL\Wordpress;

class Cart implements Page
{
	/** @var \WPAL\Wordpress */
	private $wp;
	/** @var \Jigoshop\Core\Options */
	private $options;
	/** @var Messages  */
	private $messages;
	/** @var CartServiceInterface */
	private $cartService;
	/** @var ProductServiceInterface */
	private $productService;
	/** @var Customer */
	private $customerService;
	/** @var ShippingServiceInterface */
	private $shippingService;
	/** @var \Jigoshop\Frontend\Cart */
	private $cart;

	public function __construct(Wordpress $wp, Options $options, Messages $messages, CartServiceInterface $cartService, ProductServiceInterface $productService,
		Customer $customerService, ShippingServiceInterface $shippingService, Styles $styles, Scripts $scripts)
	{
		$this->wp = $wp;
		$this->options = $options;
		$this->messages = $messages;
		$this->cartService = $cartService;
		$this->productService = $productService;
		$this->customerService = $customerService;
		$this->shippingService = $shippingService;
		$this->cart = $cartService->get($cartService->getCartIdForCurrentUser());

		$styles->add('jigoshop.shop', JIGOSHOP_URL.'/assets/css/shop.css');
		$styles->add('jigoshop.shop.cart', JIGOSHOP_URL.'/assets/css/shop/cart.css');
		$styles->add('jigoshop-vendors', JIGOSHOP_URL.'/assets/css/vendors.min.css');
		$scripts->add('jigoshop.helpers', JIGOSHOP_URL.'/assets/js/helpers.js');
		$scripts->add('jigoshop.shop', JIGOSHOP_URL.'/assets/js/shop.js');
		$scripts->add('jigoshop.shop.cart', JIGOSHOP_URL.'/assets/js/shop/cart.js');
		$scripts->add('jigoshop-vendors', JIGOSHOP_URL.'/assets/js/vendors.min.js');
		$scripts->localize('jigoshop.shop.cart', 'jigoshop', array(
			'ajax' => admin_url('admin-ajax.php', 'jigoshop'),
		));

		$wp->addAction('wp_ajax_jigoshop_cart_update_item', array($this, 'ajaxUpdateItem'));
		$wp->addAction('wp_ajax_nopriv_jigoshop_cart_update_item', array($this, 'ajaxUpdateItem'));
		$wp->addAction('wp_ajax_jigoshop_cart_select_shipping', array($this, 'ajaxSelectShipping'));
		$wp->addAction('wp_ajax_nopriv_jigoshop_cart_select_shipping', array($this, 'ajaxSelectShipping'));
		$wp->addAction('wp_ajax_jigoshop_cart_change_country', array($this, 'ajaxChangeCountry'));
		$wp->addAction('wp_ajax_nopriv_jigoshop_cart_change_country', array($this, 'ajaxChangeCountry'));
		$wp->addAction('wp_ajax_jigoshop_cart_change_state', array($this, 'ajaxChangeState'));
		$wp->addAction('wp_ajax_nopriv_jigoshop_cart_change_state', array($this, 'ajaxChangeState'));
		$wp->addAction('wp_ajax_jigoshop_cart_change_postcode', array($this, 'ajaxChangePostcode'));
		$wp->addAction('wp_ajax_nopriv_jigoshop_cart_change_postcode', array($this, 'ajaxChangePostcode'));
	}

	// TODO: Add functions for changing destination
	public function ajaxChangeCountry()
	{
		$customer = $this->customerService->getCurrent();
		$customer->setCountry($_POST['country']);

		// TODO: Recalculate cart values
		// TODO: Fetch shipping values
		// TODO: Also reload shipping estimation, tax labels and available shipping rates

		echo json_encode(array(
			'success' => true,
			'has_states' => Country::hasStates($customer->getCountry()),
			'states' => Country::getStates($customer->getCountry()),
			'subtotal' => $this->cart->getSubtotal(),
			'tax' => $this->cart->getTax(),
			'total' => $this->cart->getTotal(),
			'html' => array(
				'subtotal' => Product::formatPrice($this->cart->getSubtotal()),
				'tax' => array_map(function($tax){ return Product::formatPrice($tax); }, $this->cart->getTax()),
				'total' => Product::formatPrice($this->cart->getTotal()),
			),
		));
		exit;
	}

	public function ajaxChangeState()
	{
		$customer = $this->customerService->getCurrent();
		$customer->setState($_POST['state']);

		// TODO: Recalculate cart values

		echo json_encode(array(
			'success' => true,
			'subtotal' => $this->cart->getSubtotal(),
			'tax' => $this->cart->getTax(),
			'total' => $this->cart->getTotal(),
			'html' => array(
				'subtotal' => Product::formatPrice($this->cart->getSubtotal()),
				'tax' => array_map(function($tax){ return Product::formatPrice($tax); }, $this->cart->getTax()),
				'total' => Product::formatPrice($this->cart->getTotal()),
			),
		));
		exit;
	}

	public function ajaxChangePostcode()
	{
		$customer = $this->customerService->getCurrent();
		$customer->setPostcode($_POST['postcode']);

		// TODO: Recalculate cart values

		echo json_encode(array(
			'success' => true,
			'subtotal' => $this->cart->getSubtotal(),
			'tax' => $this->cart->getTax(),
			'total' => $this->cart->getTotal(),
			'html' => array(
				'subtotal' => Product::formatPrice($this->cart->getSubtotal()),
				'tax' => array_map(function($tax){ return Product::formatPrice($tax); }, $this->cart->getTax()),
				'total' => Product::formatPrice($this->cart->getTotal()),
			),
		));
		exit;
	}

	/**
	 * Processes change of selected shipping method and returns updated cart details.
	 */
	public function ajaxSelectShipping()
	{
		// TODO: Bullet-proof a little bit this code (i.e. invalid shipping method or something)
		$this->cart->setShippingMethod($this->shippingService->get($_POST['method']));
		$this->cartService->save($this->cart);

		echo json_encode(array(
			'success' => true,
			'subtotal' => $this->cart->getSubtotal(),
			'tax' => $this->cart->getTax(),
			'total' => $this->cart->getTotal(),
			'html' => array(
				'subtotal' => Product::formatPrice($this->cart->getSubtotal()),
				'tax' => array_map(function($tax){ return Product::formatPrice($tax); }, $this->cart->getTax()),
				'total' => Product::formatPrice($this->cart->getTotal()),
			),
		));
		exit;
	}

	/**
	 * Processes change of item quantity and returns updated item value and cart details.
	 */
	public function ajaxUpdateItem()
	{
		try {
			$this->cart->updateQuantity($_POST['item'], (int)$_POST['quantity']);
			$item = $this->cart->getItem($_POST['item']);
			$price = $this->options->get('general.price_tax') == 'with_tax' ? $item['price'] + $item['tax'] : $item['price'];

			$result = array(
				'success' => true,
				'item_price' => $price,
				'item_subtotal' => $price * $item['quantity'],
				'subtotal' => $this->cart->getSubtotal(),
				'tax' => $this->cart->getTax(),
				'total' => $this->cart->getTotal(),
				'html' => array(
					'item_price' => Product::formatPrice($price),
					'item_subtotal' => Product::formatPrice($price * $item['quantity']),
					'subtotal' => Product::formatPrice($this->cart->getSubtotal()),
					'tax' => array_map(function($tax){ return Product::formatPrice($tax); }, $this->cart->getTax()),
					'total' => Product::formatPrice($this->cart->getTotal()),
				),
			);
		} catch(Exception $e) {
			$result = array(
				'success' => false,
				'error' => $e->getMessage(),
				'html' => array(
					'subtotal' => Product::formatPrice($this->cart->getSubtotal()),
					'tax' => array_map(function($tax){ return Product::formatPrice($tax); }, $this->cart->getTax()),
					'total' => Product::formatPrice($this->cart->getTotal()),
				),
			);
		}
		$this->cartService->save($this->cart);
		echo json_encode($result);
		exit;
	}

	public function action()
	{
		if (isset($_POST['action'])) {
			switch ($_POST['action']) {
				case 'checkout':
					// TODO: Update values with non-JS mode
					$this->wp->wpRedirect($this->wp->getPermalink($this->options->getPageId(Pages::CHECKOUT)));
					exit;
				case 'update-cart':
					if (isset($_POST['cart']) && is_array($_POST['cart'])) {
						try {
							foreach ($_POST['cart'] as $item => $quantity) {
								$this->cart->updateQuantity($item, (int)$quantity);
							}
							$this->cartService->save($this->cart);
							$this->messages->addNotice(__('Successfully updated the cart.', 'jigoshop'));
						} catch(Exception $e) {
							$this->messages->addError(sprintf(__('Error occurred while updating cart: %s', 'jigoshop'), $e->getMessage()));
						}
					}
			}
		}

		if (isset($_GET['action']) && isset($_GET['item']) && $_GET['action'] === 'remove-item' && is_numeric($_GET['item'])) {
			$this->cart->removeItem((int)$_GET['item']);
			$this->cartService->save($this->cart);
			$this->messages->addNotice(__('Successfully removed item from cart.', 'jigoshop'), false);
		}
	}

	public function render()
	{
		$content = $this->wp->getPostField('post_content', $this->options->getPageId(Pages::CART));

		return Render::get('shop/cart', array(
			'content' => $content,
			'cart' => $this->cart,
			'messages' => $this->messages,
			'productService' => $this->productService,
			'customer' => $this->customerService->getCurrent(),
			'shippingMethods' => $this->shippingService->getAvailable(),
			'shopUrl' => $this->wp->getPermalink($this->options->getPageId(Pages::SHOP)),
			'showWithTax' => $this->options->get('general.price_tax') == 'with_tax',
			'showShippingCalculator' => $this->options->get('shipping.calculator'),
		));
	}
}