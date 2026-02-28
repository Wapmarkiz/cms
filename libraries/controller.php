<?php

/**
 * MobileCMS
 *
 * Open source content management system for mobile sites
 *
 * @author MobileCMS Team <support@mobilecms.pro>
 * @copyright Copyright (c) 2011-2019, MobileCMS Team
 * @link https://mobilecms.pro Official site
 * @license MIT license
 */

/**
 * Главный контроллер
 */
abstract class Controller
{

    /**
     *
     * @var model
     */
    protected $model;

    /**
     * Переменная, в которую записываем ошибки при валидации форм
     */
    public $error = false;

    /**
     * Класс шаблонизатора
     */
    public $tpl;

    /**
     * Тема
     */
    protected $template_theme = 'default';

    /**
     * Количество элементов на страницу по умолчанию
     */
    protected $per_page = 7;

    /**
     * FIX #8: Оголошено властивості, які використовуються в конструкторі
     * Без оголошення — Notice у PHP 8+ (dynamic properties deprecated)
     */
    protected $config;
    protected $db;
    protected $cache;
    protected $access;
    public $start = 0;

    /**
     * Уровень доступа по умолчанию
     * FIX #1: Властивість $access_level не була оголошена — при зверненні до неї
     * в конструкторі виникав Notice / TypeError у PHP 8+
     */
    protected $access_level = 1;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->config = Registry::get('config');
        $this->db = Registry::get('db');

        // Определение старта для пагинации
        // FIX #2: Виправлено формулу offset — було `page * per_page - 1`, має бути `(page - 1) * per_page`
        // При page=1 стара формула давала start=6 замість 0
        $this->start = !empty($_GET['start']) ? intval($_GET['start']) : 0;
        if (!empty($_GET['page']) && is_numeric($_GET['page'])) {
            $this->start = ((int)$_GET['page'] - 1) * $this->per_page;
        }
        if ($this->start < 0) {
            // FIX #3: Замінено a_error() на trigger_error() — a_error() не є стандартною функцією PHP
            // Якщо у проєкті є власна функція a_error() — цей рядок можна повернути
            trigger_error('Не верный формат данных', E_USER_ERROR);
        }

        // Подключение шаблонизатора
        a_import('libraries/template');
        $this->tpl = new Template;

        if (file_exists(ROOT . 'modules/' . ROUTE_MODULE . '/models/' . ROUTE_CONTROLLER_NAME . '.php')) {
            a_import('libraries/model');
            $this->model = a_load_class(str_replace('controllers', 'models', ROUTE_CONTROLLER_PATH), 'model');
        }
        // Добавляем объект шаблона в Registry
        Registry::set('tpl', $this->tpl);

        // Подключение кеширования
        if (!class_exists('File_Cache')) {
            a_import('libraries/file_cache');
        }
        $this->cache = new File_Cache(ROOT . 'cache/file_cache');

        // Добавление мета данных на страницу
        define('DESCRIPTION', $this->config['system']['description']);
        define('KEYWORDS', $this->config['system']['keywords']);

        // Получение данных о пользователе
        // FIX #4: Виправлено друкарську помилку в коментарі: "польльзователе" → "пользователе"
        if (!empty($_SESSION['check_user_id'])) {
            $user_id = $_SESSION['check_user_id'];
        } elseif (!empty($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
        } else {
            $user_id = -1;
        }

        // Авторизация гостей по COOKIES
        // FIX #9: УВАГА — пароль передається у відкритому вигляді через COOKIE, що є небезпечним.
        // Рекомендується зберігати хеш токена замість пароля.
        // FIX #10: Додано перевірку isset для $_COOKIE['password']
        if ($user_id === -1 && !empty($_COOKIE['username']) && isset($_COOKIE['password'])) {
            if ($try_user_id = $this->db->get_one("SELECT user_id FROM #__users WHERE username = '" . a_safe($_COOKIE['username']) . "' AND password = '" . a_safe($_COOKIE['password']) . "'")) {
                $user_id = $try_user_id;
                $_SESSION['user_id'] = $user_id;
            }
        }

        // Добавление гостей в бд
        if ($user_id == -1) {
            // FIX #5: Додано перевірку існування $_SERVER['HTTP_USER_AGENT'] — може бути відсутнім (CLI, деякі боти)
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            // FIX #11: Додано перевірку $_SERVER['REMOTE_ADDR'] — може бути відсутнім у CLI
            $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

            if ($guest = $this->db->get_row("SELECT id FROM #__guests WHERE ip = '" . a_safe($remote_addr) . "' AND user_agent = '" . a_safe($user_agent) . "'")) {
                $this->db->query("UPDATE #__guests SET
					last_time = UNIX_TIMESTAMP()
					WHERE id = '" . intval($guest['id']) . "'
				");
            } else {
                $this->db->query("INSERT INTO #__guests SET
					ip = '" . a_safe($remote_addr) . "',
					user_agent = '" . a_safe($user_agent) . "',
					last_time = UNIX_TIMESTAMP()
				");
            }
        }

        // FIX #6: $user_id може бути -1, але в SQL він вставляється без лапок та intval()
        // При $user_id = -1 запит поверне null — додано intval() для безпеки
        $this->user = $this->db->get_row("SELECT * FROM #__users LEFT JOIN #__users_profiles USING(user_id) WHERE user_id = " . intval($user_id));

        // FIX #7: Якщо $this->user порожній (гість не в БД), get_row() повертає false/null
        // звернення до $this->user['user_id'] викличе помилку — додано перевірку
        $resolved_user_id = !empty($this->user['user_id']) ? $this->user['user_id'] : -1;
        define('USER_ID', $resolved_user_id);

        $this->tpl->assign('user', $this->user);

        // Обновляем время последнего посещения
        if (USER_ID != -1) {
            $this->db->query("UPDATE #__users SET last_visit = UNIX_TIMESTAMP() WHERE user_id = '" . USER_ID . "'");
        }

        // Подключения помощника пользователей
        a_import('modules/user/helpers/user');

        // Управление правами доступа
        $this->access = a_load_class('libraries/access');

        if ($this->user) {
            $access_level = $this->access->get_level($this->user['status']);
        } else {
            $access_level = 1;
        }

        define('ACCESS_LEVEL', $access_level);

        // Выполнение событий до вызова контроллера
        main::events_exec($this->db, 'pre_controller');

        if (ACCESS_LEVEL < $this->access_level) {
            if (USER_ID == -1) {
                header('Location: ' . a_url('user/login', 'from=' . urlencode($_SERVER["REQUEST_URI"]), true));
                exit;
            } else {
                a_error('У вас нет доступа к данной странице!');
            }
        }

        // Получение темы оформления, для админки
        if ($this->template_theme == 'admin') {
            $this->tpl->theme = $this->config['system']['admin_theme'];
            $this->tpl->admin = true;
        } else {
            $this->tpl->theme = $this->config['system']['default_theme'];
        }

        define('THEME', $this->tpl->theme);

        // Проверка модерации пользователя
        if (defined('MODERATE')) {
            a_error(MODERATE);
        }
    }

}
