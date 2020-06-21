<?php

namespace MillenniumFalcon\Core\Db\Traits;

use Cocur\Slugify\Slugify;
use Doctrine\DBAL\Connection;
use MillenniumFalcon\Core\Service\ModelService;
use MillenniumFalcon\Core\Version\VersionInterface;
use Symfony\Component\HttpFoundation\Request;

trait BaseQueryTrait
{
    /**
     * @return mixed
     */
    public function delete()
    {
        $rc = static::getReflectionClass();
        $fullClass = ModelService::fullClass($this->getPdo(), 'AssetOrm');
        $result = $fullClass::data($this->getPdo(), array(
            'whereSql' => 'm.modelName = ? AND m.ormId = ?',
            'params' => array($rc->getShortName(), $this->getUniqid()),
        ));
        foreach ($result as $itm) {
            $itm->delete();
        }
        $tableName = static::getTableName();
        $sql = "DELETE FROM `{$tableName}` WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array($this->getId()));
    }

    /**
     * @return mixed
     */
    public function save($doNotSaveVersion = false)
    {
        if (!$doNotSaveVersion && $this instanceof VersionInterface) {
            $this->saveVersion();
        }

        $tableName = static::getTableName();
        $fields = array_keys(static::getFields());

        if (method_exists($this, 'getTitle')) {
            $slugify = new Slugify(['trim' => false]);
            $this->setSlug($slugify->slugify($this->getTitle()));
        }
        $this->setModified(date('Y-m-d H:i:s'));

        $sql = '';
        $params = array();
        if (!$this->getId()) {
            $sql = "INSERT INTO `{$tableName}` ";
            $part1 = '(';
            $part2 = ' VALUES (';
            foreach ($fields as $field) {
                if ($field == 'id') {
//                    continue;
                }

                $part1 .= "`$field`, ";
                $part2 .= "?, ";
                $method = 'get' . ucfirst($field);
                $params[] = $this->$method();
            }
            $part1 = rtrim($part1, ', ') . ')';
            $part2 = rtrim($part2, ', ') . ')';
            $sql = $sql . $part1 . $part2;
//            var_dump('<pre>', $sql, $params, '</pre>');exit;
        } else {
            $sql = "UPDATE `{$tableName}` SET ";
            foreach ($fields as $field) {
                if ($field == 'id') {
                    continue;
                }
                $sql .= "`$field` = ?, ";
                $method = 'get' . ucfirst($field);
                $params[] = $this->$method();
            }
            $sql = rtrim($sql, ', ') . ' WHERE id = ?';
            $params[] = $this->id;
        }

        try {
//            var_dump($params, $sql);exit;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if (!$this->getId()) {
                $this->setId($this->pdo->lastInsertId());
            }
            return $this->getId();
        } catch (\Exception $ex) {
            echo($ex->getMessage());
            exit;
        }

        return null;
    }

    /**
     * @param Connection $pdo
     * @param array $options
     * @return array|null
     */
    static public function data(Connection $pdo, $options = array())
    {
        $path = explode('\\', get_called_class());
        $className = array_pop($path);
        $request = Request::createFromGlobals();
        $previewOrmToken = $request->get('__preview_' . $className);
        if ($previewOrmToken) {
            $options['whereSql'] = 'm.versionUuid = ?';
            $options['params'] = [$previewOrmToken];
            $options['includePreviousVersion'] = 1;
        }

        $options['select'] = isset($options['select']) && !empty($options['select']) ? $options['select'] : 'm.*';
        $options['joins'] = isset($options['joins']) && !empty($options['joins']) ? $options['joins'] : null;
        $options['whereSql'] = isset($options['whereSql']) && !empty($options['whereSql']) ? "({$options['whereSql']})" : null;
        $options['params'] = isset($options['params']) && gettype($options['params']) == 'array' && count($options['params']) ? $options['params'] : [];
        $options['sort'] = isset($options['sort']) && !empty($options['sort']) ? $options['sort'] : 'm.rank';
        $options['order'] = isset($options['order']) && !empty($options['order']) ? $options['order'] : 'ASC';
        $options['groupby'] = isset($options['groupby']) && !empty($options['groupby']) ? $options['groupby'] : null;
        $options['page'] = isset($options['page']) ? $options['page'] : 1;
        $options['limit'] = isset($options['limit']) ? $options['limit'] : 0;
        $options['orm'] = isset($options['orm']) ? $options['orm'] : 1;
        $options['debug'] = isset($options['debug']) ? $options['debug'] : 0;
        $options['idArray'] = isset($options['idArray']) ? $options['idArray'] : 0;
        $options['includePreviousVersion'] = isset($options['includePreviousVersion']) ? $options['includePreviousVersion'] : 0;

        $options['oneOrNull'] = isset($options['oneOrNull']) ? $options['oneOrNull'] == true : false;
        if ($options['oneOrNull']) {
            $options['limit'] = 1;
            $options['page'] = 1;
        }

        $options['count'] = isset($options['count']) ? $options['count'] == true : false;
        if ($options['count']) {
            $options['orm'] = false;
            $options['oneOrNull'] = true;
            $options['select'] = 'COUNT(*) AS count';
            $options['page'] = null;
            $options['limit'] = null;
        }

        $myClass = get_called_class();
        $tableName = static::getTableName();
        $fields = array_keys(static::getFields());

        $sql = "SELECT {$options['select']} FROM `{$tableName}` AS m";
        $sql .= $options['joins'] ? ' ' . $options['joins'] : '';
        if ($options['includePreviousVersion']) {
            $sql .= $options['whereSql'] ? ' WHERE ' . $options['whereSql'] : '';
        } else {
            $sql .= ' WHERE m.versionId IS NULL ' . ($options['whereSql'] ? ' AND (' . $options['whereSql'] . ')' : '');
        }
        $sql .= $options['groupby'] ? ' GROUP BY ' . $options['groupby'] : '';
        if ($options['sort']) {
            $sql .= " ORDER BY {$options['sort']} {$options['order']}";
        }
        if ($options['limit'] && $options['page']) {
            $sql .= " LIMIT " . (($options['page'] - 1) * $options['limit']) . ", " . $options['limit'];
        }

        if ($options['debug']) {
            while (@ob_end_clean()) ;
            var_dump($sql, $options['params']);
            exit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($options['params']);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($options['orm']) {
            $orms = array();
            foreach ($result as $itm) {
                $orm = new $myClass($pdo);
                foreach ($fields as $field) {
                    if (isset($itm[$field])) {
                        $method = 'set' . ucfirst($field);
                        $orm->$method($itm[$field]);
                    }
                }
                if ($options['idArray']) {
                    $orms[$orm->getId()] = $orm;
                } else {
                    $orms[] = $orm;
                }
            }
            $result = $orms;
        }

        if ($options['oneOrNull']) {
            $result = reset($result) ?: null;
        }

        return $result;
    }

    /**
     * @param $pdo
     * @param array $options
     * @return array|null
     */
    static public function active($pdo, $options = array())
    {
        if (isset($options['whereSql'])) {
            $options['whereSql'] .= ($options['whereSql'] ? ' AND ' : '') . 'm.status = 1';
        } else {
            $options['whereSql'] = 'm.status = 1';
        }
        return static::data($pdo, $options);
    }

    /**
     * @param Connection $pdo
     * @param $id
     * @return array|null
     */
    static public function getByField(Connection $pdo, $field, $value)
    {
        return static::data($pdo, array(
            'whereSql' => "m.$field = ?",
            'params' => array($value),
            'oneOrNull' => 1,
        ));
    }

    /**
     * @param Connection $pdo
     * @param $id
     * @return array|null
     */
    static public function getById(Connection $pdo, $id)
    {
        return static::getByField($pdo, 'id', $id);
    }

    /**
     * @param Connection $pdo
     * @param $slug
     * @return array|null
     */
    static public function getBySlug(Connection $pdo, $slug)
    {
        return static::getByField($pdo, 'slug', $slug);
    }
}