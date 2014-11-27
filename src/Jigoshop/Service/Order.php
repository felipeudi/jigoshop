<?php

namespace Jigoshop\Service;

use Jigoshop\Core\Types;
use Jigoshop\Entity\EntityInterface;
use Jigoshop\Entity\Order\Item;
use Jigoshop\Entity\Order\Status;
use Jigoshop\Factory\Order as Factory;
use Jigoshop\Frontend\Cart;
use WPAL\Wordpress;

/**
 * Orders service.
 *
 * @package Jigoshop\Service
 * @author Amadeusz Starzykiewicz
 */
class Order implements OrderServiceInterface
{
	/** @var \WPAL\Wordpress */
	private $wp;
	/** @var Factory */
	private $factory;

	public function __construct(Wordpress $wp, Factory $factory)
	{
		$this->wp = $wp;
		$this->factory = $factory;

		$wp->addAction('save_post_'.Types\Order::NAME, array($this, 'savePost'), 10);
	}

	/**
	 * Finds order specified by ID.
	 *
	 * @param $id int Order ID.
	 * @return \Jigoshop\Entity\Order
	 */
	public function find($id)
	{
		$post = null;

		if ($id !== null) {
			$post = $this->wp->getPost($id);
		}

		return $this->factory->fetch($post);
	}

	/**
	 * Finds item for specified WordPress post.
	 *
	 * @param $post \WP_Post WordPress post.
	 * @return Order Item found.
	 */
	public function findForPost($post)
	{
		return $this->factory->fetch($post);
	}

	/**
	 * Finds order specified using WordPress query.
	 * TODO: Replace \WP_Query in order to make Jigoshop testable
	 *
	 * @param $query \WP_Query WordPress query.
	 * @return array Collection of found orders
	 */
	public function findByQuery($query)
	{
		// Fetch only IDs
		$query->query_vars['fields'] = 'ids';
		$results = $query->get_posts();
		$that = $this;
		// TODO: Maybe it is good to optimize this to fetch all found orders data at once?
		$orders = array_map(function ($order) use ($that){
			return $that->find($order);
		}, $results);

		return $orders;
	}

	/**
	 * Saves order to database.
	 *
	 * @param $object EntityInterface Order to save.
	 * @throws Exception
	 */
	public function save(EntityInterface $object)
	{
		if (!($object instanceof \Jigoshop\Entity\Order)) {
			throw new Exception('Trying to save not an order!');
		}

		/** @var \Jigoshop\Entity\Order $object */
		$object->setUpdatedAt(new \DateTime());

		if (!$object->getNumber()) {
			$object->setNumber($this->getNextOrderNumber());
		}

		$fields = $object->getStateToSave();
		$created = false;

		if (!$object->getId()) {
			$object->setNumber($this->getNextOrderNumber());
			$post = $this->wp->wpInsertPost(array(
				'post_type' => Types::ORDER,
				'post_title' => $object->getTitle(),
				'post_status' => $object->getStatus(),
			));

			if (!is_int($post) || $post === 0) {
				throw new Exception(__('Unable to save order. Please try again.', 'jigoshop'));
			}

			$object->setId($post);
			$created = true;
		}

		if (isset($fields['id'])) {
			unset($fields['id']);
		}

		$wpdb = $this->wp->getWPDB();

		if (!$created && (isset($fields['status']) || isset($fields['number']))) {
			$wpdb->update($wpdb->posts, array(
				'post_title' => $object->getTitle(),
				'post_status' => $object->getStatus(),
			), array('ID' => $object->getId()));
		}

		if (isset($fields['customer_note']) || isset($fields['status'])) {
			// We don't need to save these values - they are stored by WordPress itself.
			unset($fields['customer_note'], $fields['status']);
		}

		if (isset($fields['items'])) {
			$existing = array_map(function($item){
				/** @var $item Item */
				return $item->getId();
			}, $fields['items']);
			$this->removeAllExcept($object->getId(), $existing);

			foreach ($fields['items'] as $item) {
				/** @var $item Item */
				$data = array(
					'order_id' => $object->getId(),
					'product_id' => $item->getProduct() ? $item->getProduct()->getId() : null,
					'product_type' => $item->getType(),
					'title' => $item->getName(),
					'price' => $item->getPrice(),
					'tax' => $item->getTotalTax(),
					'quantity' => $item->getQuantity(),
					'cost' => $item->getCost(),
				);

				if ($item->getId() !== null) {
					$wpdb->update($wpdb->prefix.'jigoshop_order_item', $data, array('id' => $item->getId()));
				} else {
					$wpdb->insert($wpdb->prefix.'jigoshop_order_item', $data);
					$item->setId($wpdb->insert_id);
				}

				foreach ($item->getTax() as $class => $value) {
					$wpdb->replace($wpdb->prefix.'jigoshop_order_item_meta', array(
						'item_id' => $item->getId(),
						'meta_key' => 'tax_'.$class,
						'meta_value' => $value,
					));
				}

				foreach ($item->getAllMeta() as $meta) {
					/** @var $meta Item\Meta */
					$wpdb->replace($wpdb->prefix.'jigoshop_order_item_meta', array(
						'item_id' => $item->getId(),
						'meta_key' => $meta->getKey(),
						'meta_value' => $meta->getValue(),
					));
				}
			}

			unset($fields['items']);
		}

		foreach ($fields as $field => $value) {
			$this->wp->updatePostMeta($object->getId(), $field, $this->wp->getHelpers()->escSql($value));
		}
	}

	/**
	 * Prepares order based on cart.
	 *
	 * @param Cart $cart Cart to fetch data from.
	 * @return Order Prepared order.
	 */
	public function createFromCart(Cart $cart)
	{
		return $this->factory->fromCart($cart);
	}

	/**
	 * Save the order data upon post saving.
	 *
	 * @param $id int Post ID.
	 */
	public function savePost($id)
	{
		// Do not save order when trashing or restoring from trash
		if (!isset($_GET['action'])) {
			$order = $this->factory->create($id);
			$this->save($order);
		}
	}

	/**
	 * @param $month int Month to find orders from.
	 * @return array List of orders from selected month.
	 */
	public function findFromMonth($month)
	{
		$restriction = function( $where = '' ) use ($month) {
			$year = (int)date('Y');

			$firstDay = strtotime("{$year}-{$month}-01");
			$lastDay = strtotime('-1 second', strtotime('+1 month', $firstDay));

			$after = date('Y-m-d H:i:s', $firstDay);
			$before = date('Y-m-d H:i:s', $lastDay);

			$where .= " AND post_date >= '$after'";
			$where .= " AND post_date <= '$before'";

			return $where;
		};

		$statuses = Status::getStatuses();
		unset($statuses[Status::CANCELLED], $statuses[Status::REFUNDED]);

		$this->wp->addFilter('posts_where', $restriction);
		$query = new \WP_Query(array(
			'post_status' => $statuses,
			'post_type' => Types::ORDER,
			'suppress_filters' => false,
			'fields' => 'ids',
			'order' => 'DESC',
			'orderby' => 'post_date',
			'numberposts' => -1,
		));
		$results = $this->findByQuery($query);
		$this->wp->removeFilter('posts_where', $restriction);

		return $results;
	}

	/**
	 * @return array List of orders that are too long in Pending status.
	 */
	public function findOldPending()
	{
		$this->wp->addFilter('posts_where', array($this, 'ordersFilter'));
		$query = new \WP_Query(array(
			'post_status' => Status::PENDING,
			'post_type' => Types::ORDER,
			'suppress_filters' => false,
			'fields' => 'ids',
		));
		$results = $this->findByQuery($query);
		$this->wp->removeFilter('posts_where', array($this, 'ordersFilter'));

		return $results;
	}

	/**
	 * @return array List of orders that are too long in Processing status.
	 */
	public function findOldProcessing()
	{
		$this->wp->addFilter('posts_where', array($this, 'ordersFilter'));
		$query = new \WP_Query(array(
			'post_status' => Status::PROCESSING,
			'post_type' => Types::ORDER,
			'suppress_filters' => false,
			'fields' => 'ids',
		));
		$results = $this->findByQuery($query);
		$this->wp->removeFilter('posts_where', array($this, 'ordersFilter'));

		return $results;
	}

	/**
	 * @param string $when Base query.
	 * @return string Query for orders older than 30 days.
	 * @internal
	 */
	public function ordersFilter($when = '')
	{
		return $when.$this->wp->getWPDB()->prepare(' AND post_date < %s', date('Y-m-d', time() - 30 * 24 * 3600));
	}

	/**
	 * @param $order int Order ID.
	 * @param $ids array IDs to preserve.
	 */
	public function removeAllExcept($order, $ids)
	{
		$wpdb = $this->wp->getWPDB();
		$ids = join(',', array_filter(array_map(function($item){ return (int)$item; }, $ids)));
		// Support for removing all items
		if (empty($ids)) {
			$ids = '0';
		}
		$query = $wpdb->prepare("DELETE FROM {$wpdb->prefix}jigoshop_order_item WHERE id NOT IN ({$ids}) AND order_id = %d", array($order));
		$wpdb->query($query);
	}

	private function getNextOrderNumber()
	{
		$wpdb = $this->wp->getWPDB();
		return $wpdb->get_var($wpdb->prepare("SELECT MAX(ID)+1 FROM {$wpdb->posts} WHERE post_type = %s", array(Types::ORDER)));
	}
}
