<?php

defined('_JEXEC') or die('Restricted access');

require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';

class PlgFabrik_FormProcess extends PlgFabrik_Form
{
    private $fields;
    private $infos;
    private $condition;
    private $userTask;
    private $gateway;
    private $serviceTask;

    private function setFieldsAdm() {
        $params = $this->getParams();
        $worker = FabrikWorker::getPluginManager();

        $fields = array(
            'p_processo',
            'p_titulo',
            'p_etapa_anterior',
            'p_elemento_tipo',
            'p_lista_origem',
            'p_elemento_acao',
            'p_condicional_texto',
            'p_php',
            'p_c_plugin'
        );

        $r = array();
        foreach ($fields as $field) {
            $elId = $params->get($field);
            $r[$field] = $worker->getElementPlugin($elId)->element;
        }
        $r['p_valor_tipo'] = json_decode($params->get('p_valor_tipo'))->p_type;
        $r['p_c_operacao'] = str_replace('.', '___', $params->get('p_c_operacao'));
        $r['p_c_elemento'] = str_replace('.', '___', $params->get('p_c_elemento'));
        $r['p_c_condicional'] = str_replace('.', '___', $params->get('p_c_condicional'));
        $r['p_c_valor'] = str_replace('.', '___', $params->get('p_c_valor'));

        $r = (Object) $r;

        $this->fields = $r;
    }

    private function isServiceTask() {
        $fields = $this->fields;
        $formModel = $this->getModel();
        $listName = $formModel->getTableName();
        $data = $formModel->formData;
        if (is_null($data)) {
            $data = $formModel->getData();
        }

        $type = $data[$fields->p_elemento_tipo->name];
        if (is_null($type)) {
            $type = $data[$listName . '___' . $fields->p_elemento_tipo->name];
        }
        if (is_array($type)) {
            $type = $type[0];
        }

        $serviceTask_values = $fields->p_valor_tipo;
        if (!in_array($type, $serviceTask_values)) {
            return false;
        }
        return true;
    }

    private function findUserTask($id) {
        $db = JFactory::getDbo();
        $formModel = $this->getModel();
        $table = $formModel->getTableName();
        $fields = $this->fields;

        $query = $db->getQuery(true);
        $query->select("{$fields->p_elemento_tipo->name}, {$fields->p_etapa_anterior->name}, {$fields->p_lista_origem->name}")->from($table)->where('id = ' . (int)$id);
        $db->setQuery($query);
        $result = $db->loadAssoc();

        if ($result[$fields->p_elemento_tipo->name] === 'userTask') {
            return $result[$fields->p_lista_origem->name];
        }

        $eAnteriorId = $result[$fields->p_etapa_anterior->name];

        if ($eAnteriorId) {
            $this->findUserTask($eAnteriorId);
        }
        else {
            return NULL;
        }
    }

    private function getListOrigem() {
        $formModel = $this->getModel();
        $data = $formModel->getData();
        $listName = $formModel->getTableName();
        $fields = $this->fields;
        $listOrigemId = NULL;

        $etapaAnterior = $data[$listName . '___' . $fields->p_etapa_anterior->name . '_raw'];
        if (is_array($etapaAnterior)) {
            $etapaAnterior = $etapaAnterior[0];
        }
        if ($etapaAnterior) {
            $listOrigemId = $this->findUserTask($etapaAnterior);
        }

        return $listOrigemId;
    }

    private function getUrlAdminListaOrigem() {
        if ($this->isServiceTask()) {

            $listOrigemId = $this->getListOrigem();
            if (!is_null($listOrigemId)) {
                $db = JFactory::getDbo();
                $query = $db->getQuery(true);
                $query->select('form_id')->from('#__fabrik_lists')->where('id = ' . (int)$listOrigemId);
                $db->setQuery($query);
                $formId = $db->loadResult();

                return COM_FABRIK_LIVESITE . "administrator/index.php?option=com_fabrik&view=form&layout=edit&id=$formId";
            }
        }

        return '';
    }

    public function getTopContent() {
        $this->setFieldsAdm();

        $this->instanceJS();

        return true;
    }

    private function instanceJS() {
        $input = $this->app->input;
        $params = $this->getParams();

        $opts          = new stdClass;
        $opts->urlAdmin = $this->getUrlAdminListaOrigem();
        $opts->view = $input->get('view');
        $opts->button_label = $params->get('p_button_label', 'Configure Plugin');
        $opts->button_style = $params->get('p_button_style', 'btn-default');
        $opts = json_encode($opts);

        $jsFiles = array();
        $jsFiles['Fabrik'] = 'media/com_fabrik/js/fabrik.js';
        $jsFiles['FabrikProcess'] = 'plugins/fabrik_form/process/process.js';

        $script = "new FabrikProcess($opts);";
        FabrikHelperHTML::script($jsFiles, $script);
    }

    private function defineAction() {
        $fields = $this->fields;
        $formModel = $this->getModel();
        $data = $formModel->formData;

        $type = $data[$fields->p_elemento_tipo->name];
        if (is_array($type)) {
            $type = $type[0];
        }

        $serviceTask_values = $fields->p_valor_tipo;
        switch ($type) {
            case 'userTask':
                $this->processUserTask();
                break;
            case 'exclusiveGateway':
                $this->processExclusiveGateway();
                break;
            case in_array($type, $serviceTask_values):
                $this->processServiceTask();
                break;
        }
    }

    private function getEtapa($id) {
        $formModel = $this->getModel();
        $table = $formModel->getTableName();
        $fields = $this->fields;

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select("id, {$fields->p_elemento_acao->name}, {$fields->p_titulo->name}, {$fields->p_c_plugin->name}, {$fields->p_elemento_tipo->name}, {$fields->p_condicional_texto->name}, {$fields->p_etapa_anterior->name}, {$fields->p_lista_origem->name}")->from($table)->where('id = ' . (int)$id);
        $db->setQuery($query);
        return $db->loadAssoc();
    }

    private function setCondition($etapa) {
        $fields = $this->fields;
        $worker = FabrikWorker::getPluginManager();
        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();
        $db = JFactory::getDbo();

        $row = (array) $listModel->getRow($etapa['id'], false, true);

        $operacoes = (array) $row[$fields->p_c_operacao];
        $elementos = (array) $row[$fields->p_c_elemento . '_raw'];
        $condicionais = (array) $row[$fields->p_c_condicional];
        $valores = (array) $row[$fields->p_c_valor];

        $i=0;
        if ($elementos) {
            foreach ($elementos as $elemento) {
                $element = $worker->getElementPlugin($elemento)->element;
                $groupId = $element->group_id;
                $query = $db->getQuery(true);
                $query->select('form_id')->from("#__fabrik_formgroup")->where('group_id = ' . (int)$groupId);
                $db->setQuery($query);
                $form_id_cond = $db->loadResult();
                $query = $db->getQuery(true);
                $query->select('db_table_name')->from("#__fabrik_lists")->where('form_id = ' . (int)$form_id_cond);
                $db->setQuery($query);
                $table_name = $db->loadResult();
                $elementos[$i] = "{{$table_name}___{$element->name}}";
                $i++;
            }

            $sentences = count($elementos);
            $this->condition = "(";
            for ($i = 0; $i < $sentences; $i++) {
                $sent = "('{$elementos[$i]}' {$condicionais[$i]} '{$valores[$i]}')";
                if ($elementos[$i + 1]) {
                    $sent .= " {$operacoes[$i]} ";
                }
                $this->condition .= $sent;
            }
            $this->condition .= ");";
        }
    }

    private function getListaOrigemInfo() {
        $fields = $this->fields;
        $listId = $this->userTask[$fields->p_lista_origem->name];
        $db = JFactory::getDbo();

        $info = new stdClass();

        $query = $db->getQuery(true);
        $query->select('db_table_name, form_id')->from("#__fabrik_lists")->where('id = ' . (int)$listId);
        $db->setQuery($query);
        $result = $db->loadObject();

        $info->table = $result->db_table_name;
        $info->form_id = $result->form_id;

        $query = $db->getQuery(true);
        $query->select('params')->from("#__fabrik_forms")->where('id = ' . (int)$info->form_id);
        $db->setQuery($query);
        $info->params = $db->loadResult();

        return $info;
    }

    private function createPluginConfig() {
        $fields = $this->fields;
        $infos = $this->infos;
        $db = JFactory::getDbo();

        $listaOrigem = $this->getListaOrigemInfo();
        $params = (array) json_decode($listaOrigem->params);

        $configPlugin = array();
        if ($this->condition) {
            $configPlugin['plugin_condition'] = array($this->condition);
        }
        $configPlugin['plugin_state'] = array('1');
        $configPlugin['plugins'] = array($this->serviceTask[$fields->p_c_plugin->name]);
        $configPlugin['plugin_locations'] = array('both');
        $configPlugin['plugin_events'] = array($this->userTask[$fields->p_elemento_acao->name]);

        $desc = new stdClass();
        $desc->title = $this->serviceTask[$fields->p_titulo->name];
        $desc->userTask = $this->userTask['id'];
        if ($this->gateway) {
            $desc->gateway = $this->gateway['id'];
        }
        $desc->serviceTask = $this->serviceTask['id'];
        $configPlugin['plugin_description'] = array(json_encode($desc));

        $is_new = true;
        $i = 0;
        foreach ($params['plugins'] as $key => $item) {
            if (($item === $this->serviceTask[$fields->p_c_plugin->name]) && ($params['plugin_description'] === $configPlugin['plugin_description'])) {
                $is_new = false;
                $i = $key;
                break;
            }
        }

        foreach ($configPlugin as $key => $item) {
            if ($is_new) {
                if ((is_array($item)) && key_exists($key, $params)) {
                    $params[$key] = array_merge($params[$key], $item);
                } else {
                    $params[$key] = $item;
                }
            }
            else {
                $params[$key][$i] = $item[0];
            }
        }

        $params = (Object) $params;
        $update = new stdClass();
        $update->id = $listaOrigem->form_id;
        $update->params = json_encode($params);

        $db->updateObject("#__fabrik_forms", $update, 'id');
    }

    private function getExclusiveGateways($id) {
        $formModel = $this->getModel();
        $table = $formModel->getTableName();
        $fields = $this->fields;

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('id')->from($table)->where("{$fields->p_elemento_tipo->name} = 'exclusiveGateway' AND {$fields->p_etapa_anterior->name} = '{$id}'");
        $db->setQuery($query);

        return $db->loadColumn();
    }

    private function getServicesTask($ids) {
        $servicesTask = array();

        $formModel = $this->getModel();
        $table = $formModel->getTableName();
        $fields = $this->fields;
        $db = JFactory::getDbo();

        foreach($ids as $id) {
            $query = $db->getQuery(true);
            $query->select('id')->from($table)->where("{$fields->p_etapa_anterior->name} = '{$id}'");
            $db->setQuery($query);
            $servicesTask = array_merge($servicesTask, $db->loadColumn());
        }

        return $servicesTask;
    }

    private function processServiceTask($id = '0') {
        if ($id === '0') {
            $formModel = $this->getModel();
            $table = $formModel->getTableName();
            $id = $formModel->formData[$table . '___id'];
        }

        $fields = $this->fields;
        $serviceTask_values = $fields->p_valor_tipo;
        $this->infos = new stdClass();

        $etapa = $this->getEtapa($id);

        if ($etapa[$fields->p_elemento_tipo->name] === 'userTask') {
            $this->infos->lista_origem = $etapa[$fields->p_lista_origem->name];
            $this->userTask = $etapa;
            $this->createPluginConfig();
            return;
        }
        else if ($etapa[$fields->p_elemento_tipo->name] === 'exclusiveGateway') {
            $this->condition = $etapa[$fields->p_condicional_texto->name];
            $this->gateway = $etapa;

            if (!$this->condition) {
                $this->setCondition($etapa);
            }
        }
        else if (in_array($etapa[$fields->p_elemento_tipo->name], $serviceTask_values)) {
            $this->serviceTask = $etapa;
        }

        if ($etapa[$fields->p_etapa_anterior->name]) {
            $this->processServiceTask($etapa[$fields->p_etapa_anterior->name]);
        }
    }

    private function processUserTask() {
        $formModel = $this->getModel();
        $table = $formModel->getTableName();
        $id = $formModel->formData[$table . '___id'];

        $gateways = $this->getExclusiveGateways($id);
        if (!empty($gateways)) {
            $servicesTask = $this->getServicesTask($gateways);
        }
        else {
            $servicesTask = $this->getServicesTask(array($id));
        }

        if (!empty($servicesTask)) {
            foreach ($servicesTask as $id) {
                $this->processServiceTask($id);
            }
        }
    }

    private function processExclusiveGateway() {
        $formModel = $this->getModel();
        $table = $formModel->getTableName();
        $id = $formModel->formData[$table . '___id'];

        $servicesTask = $this->getServicesTask(array($id));

        if (!empty($servicesTask)) {
            foreach ($servicesTask as $id) {
                $this->processServiceTask($id);
            }
        }
    }

    public function onAfterProcess()
    {
        $this->setFieldsAdm();

        $this->defineAction();
    }
}