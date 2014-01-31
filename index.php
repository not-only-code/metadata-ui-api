<?php
/**
 * Post Meta Box (UI + form handler) manager class.
 *
 * Handles the construction of the form container (here a meta box), and
 * bridges the gap between the form submission and saving the fields'
 * data.
 *
 * Business logic specific to the object type is defined by the class by default,
 * but can easily be overridden at the field object level (in a subclass).
 *
 */
class WP_Post_Meta_Box_Manager {

	/**
	 * Related fields
	 *
	 * @var array
	 */
	var $fields = array();

	/**
	 * Metabox title
	 *
	 * @var string
	 */
	var $title = '';

	/**
	 * Unique identifier
	 *
	 * @var string
	 */
	var $name = '';

	function __construct( $args = array() ) {
		$keys = array_keys( get_class_vars( __CLASS__ ) );
		foreach ( $keys as $key ) {
			if ( isset( $args[ $key ] ) )
				$this->$key = $args[ $key ];
		}

		if ( empty( $this->name ) )
			$this->name = sanitize_title( $this->title );

		// Register object-specific UI
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// Register data-submission handler
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box.
	 */
	function add_meta_box() {
		add_meta_box(
			$this->name,                        // ID
			$this->title,                       // title
			array( $this, 'metabox_callback' ), // render callback
			'post',                             // screen
			'advanced',                         // context
			'default',                          // priority
			null                                // callback_args
		);
	}

	/**
	 * Output the contents of the meta box.
	 */
	function metabox_callback() {
		$this->setup_object_data( get_post() );
		$this->render_form();
	}

	/**
	 * The object's data is stored within this UI/form handler object.
	 * This keeps all business logic flowing through the manager by default,
	 * and doesn't require customizing a field for each different object
	 * type. Business logic can easily be overridden at the Field object level.
	 *
	 * @param WP_Post $object
	 */
	function setup_object_data( $object ) {
		$this->object_data = $object;
	}

	/**
	 * Render fields' input elements.
	 */
	function render_form() {
		wp_nonce_field( $this->name, $this->name );
		foreach ( $this->fields() as $field ) {
			$field->render_input_element();
		}
	}

	/**
	 * save_post handler
	 */
	function save( $post_id, $post ) {
		// Setup object data
		$this->setup_object_data( $post );

		if ( wp_is_post_revision( $post_id ) )
			return;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		foreach ( $this->fields() as $field ) {
			$field->save_field( $field );
		}
	}

	/**
	 * Default save method specific to a post type.
	 */
	function save_field( $field ) {
		if ( ! $field->authorization( $field ) ) {
			return false;
		}
		$value = $field->sanitize( $field->post_value() );
		update_post_meta( $this->object_data->ID, $field->name, $value );
	}


	/**
	 * Relate a field to the post meta box.
	 * @param string $field_name
	 */
	function add_field( $field_name ) {
		$field = WP_Field_Manager::get_field( 'post', $field_name );
		$field->container = $this;
		$this->fields[] = $field;
	}

	/**
	 * Get field objects related to the post meta box.
	 */
	function fields() {
		return $this->fields;
	}

	/**
	 * Default get method specific to a post type.
	 *
	 * @param  string $field_name
	 *
	 * @return mixed
	 */
	function value( $field_name ) {
		return get_post_meta( $this->object_data->ID, $field_name, true );
	}

	/**
	 * Default authorization callback for post meta.
	 *
	 * @param  Field $field Field object.
	 *
	 * @return bool Authorization yay or nay.
	 */
	function authorization( $field ) {
		return current_user_can( 'edit_post_meta', $this->object_data->ID, $field->name );
	}
}

/**
 * A field class defines a general field type. It holds business logic
 * that may be specific to that field type, as well as view rendering.
 *
 * An instance of a field class defines a singular field, which relates
 * to one or many object types (post types, users, etc.).
 *
 * Business logic can be overridden at the object level, but by default
 * defers to the form object.
 */
class Field {

	/**
	 * Unique identifier
	 *
	 * @var string
	 */
	var $name;

	/**
	 * Label
	 *
	 * @var string
	 */
	var $label;

	/**
	 * Type of object this field relates to.
	 *
	 * @var string
	 */
	var $object_type;

	/**
	 * Form object.
	 *
	 * @var TBD
	 */
	var $container;

	function __construct( $args = array() ) {
		$keys = array_keys( get_class_vars( __CLASS__ ) );
		foreach ( $keys as $key ) {
			if ( isset( $args[ $key ] ) )
				$this->$key = $args[ $key ];
		}
	}

	/**
	 *
	 */
	function sanitize( $value ) {
		return $value;
	}

	/**
	 * Authorization
	 * @return [type] [description]
	 */
	function authorization() {
		return $this->container->authorization( $this );
	}

	function value() {
		return $this->container->value( $this->name );
	}

	/**
	 * Retrieve a user-submitted value for a field.
	 *
	 * This may need to call the field object/class, depending how
	 * $_POST variable names are built.
	 */
	function post_value() {
		return $_POST[$this->name];
	}

	function save_field() {
		return $this->container->save_field( $this );
	}
}


/**
 * A textfield class.
 */
class Field_text extends Field {

	function render_input_element() {
		?>
		<label>
			<span class="field-title"><?php echo esc_html( $this->label ); ?></span>
			<input type="text"
			       name="<?php echo $this->name ?>"
			       value="<?php echo $this->value() ?>">
		</label> <?php
	}
}

/**
 * A manager for registering fields.
 */
class WP_Field_Manager {

	/**
	 * [$fields description]
	 * @var [type]
	 */
	static $fields;

	/**
	 * Field registration.
	 *
	 * Abstracted from the UI view class, so that the field's business logic
	 * and data describing it can be accessed independently.
	 *
	 * Data about fields is stored in a nested array.
	 */
	public static function add_field( $args ) {
		$defaults = array(
			'name' => null, // unique identifier
			'object_types' => array(), // WP objects (post, page, user, etc.) this field applies to
			'form_object_type' => 'text'
		);
		$args = wp_parse_args( $args, $defaults );
		self::_set_field( $args );
	}

	public static  function get_field( $object_type, $field_name ) {
		if ( isset( self::$fields[$object_type][$field_name] ) )
			return self::$fields[$object_type][$field_name];
	}

	public static function get_fields( $object_type ) {
		if ( ! empty( self::$fields[$object_type] ) )
			return self::$fields[$object_type];
	}
	/**
	 * Internally store field data.
	 *
	 * @param array $args {
	 *     Field options.
	 *
	 *     @type string   $name                   Unique identifier.
	 *     @type array    $object_types           WordPress objects the field applies to.
	 *     @type string   $form_object_type       Form object type.
	 *     @type callback $authorization_callback
	 *     @type callback $sanitization_callback
	 * }
	 */
	protected static function _set_field( $args ) {
		foreach ( $args['object_types'] as $object_type ) {
			$classname =  sprintf( "Field_%s", $args['form_object_type'] );
			self::$fields[$object_type][$args['name']] = new $classname( $args );
		}
	}
}

/**
 * Example Developer Usage
 */
add_action( 'init', 'register_custom_fields_and_form_containers' );
/**
 * Register custom fields and an example form contianer.
 */
function register_custom_fields_and_form_containers() {

	WP_Field_Manager::add_field( array(
		'object_types' => array( 'post', 'page' ),
		'name' => 'background_color',
		'type' => 'text', // Relates to a pre-defined class.
		'label' => 'Background Color'
	) );

	// Insantiate a container.
	$container = new WP_Post_Meta_Box_Manager( array(
		'title' => 'Post Details'
	) );
	// Relate a registered field to the container.
	$container->add_field( 'background_color' );
}
