<?php

class AiMarkerExtension extends Minz_Extension {
	private $default_system_prompt;

	// 定义配置默认值常量
	const DEFAULT_MODEL = 'gpt-3.5-turbo';

	public function init() {
		$this->registerTranslates();
		
		// 获取默认系统提示词
		$this->default_system_prompt = _t('ext.ai_marker.default_system_prompt');
		
		// 注册钩子，在文章入库前进行处理，避免重复判断
		$this->registerHook('entry_before_insert', array($this, 'processArticleHook'));
	}
	
	public function handleConfigureAction() {
		$this->registerTranslates();
		
		if (Minz_Request::isPost()) {
			FreshRSS_Context::$user_conf->openai_api_key = Minz_Request::param('openai_api_key', '');
			FreshRSS_Context::$user_conf->openai_proxy_url = Minz_Request::param('openai_proxy_url', '');
			FreshRSS_Context::$user_conf->openai_model = Minz_Request::param('openai_model', self::DEFAULT_MODEL);
			FreshRSS_Context::$user_conf->system_prompt = Minz_Request::param('system_prompt', $this->default_system_prompt);
			FreshRSS_Context::$user_conf->save();
			
			Minz_Request::good(_t('feedback.conf.updated'), array(
				'params' => array('config' => 'display')
			));
		}
	}
	
	public function processArticleHook($entry) {
		// 获取文章内容
		$title = $entry->title();
		$content = $entry->content();
		
		// 调用LLM进行判断
		$result = $this->askLLM($title, $content);
		
		if ($result === 'USELESS') {
			// 如果LLM判断文章无用，将其标记为已读
			$entry->_isRead(true);
			Minz_Log::debug(_t('ext.ai_marker.article_marked_read') . ': ' . $title);
		}
		
		return $entry;
	}
	
	private function askLLM($title, $content) {
		// 从用户配置中获取API密钥
		$api_key = FreshRSS_Context::$user_conf->openai_api_key ?? '';
		if (empty($api_key)) {
			Minz_Log::error(_t('ext.ai_marker.error_missing_api_key'));
			return 'USEFUL'; // 默认认为有用，以避免错误地过滤内容
		}
		
		// 获取其他配置
		$proxy_url = FreshRSS_Context::$user_conf->openai_proxy_url ?? '';
		$model = FreshRSS_Context::$user_conf->openai_model ?? self::DEFAULT_MODEL;
		$system_prompt = FreshRSS_Context::$user_conf->system_prompt ?? $this->default_system_prompt;
		
		// 准备向OpenAI API发送请求
		$api_url = !empty($proxy_url) ? $proxy_url : 'https://api.openai.com/v1/chat/completions';
		
		// 准备请求数据
		$data = array(
			'model' => $model,
			'messages' => array(
				array(
					'role' => 'system',
					'content' => $system_prompt
				),
				array(
					'role' => 'user',
					'content' => "标题: $title\n\n内容: $content"
				)
			),
			'temperature' => 0.1 // 低温度以获得更确定的回答
		);
		
		// 发送请求
		$response = $this->sendRequest($api_url, $data, $api_key);
		
		// 解析响应
		if ($response) {
			$json = json_decode($response, true);
			if (isset($json['choices'][0]['message']['content'])) {
				$content = $json['choices'][0]['message']['content'];
				
				// 尝试提取JSON部分
				if (preg_match('/\{.*\}/s', $content, $matches)) {
					$jsonStr = $matches[0];
					$contentJson = json_decode($jsonStr, true);
					
					// 检查JSON中是否包含evaluation字段
					if ($contentJson && isset($contentJson['evaluation'])) {
						$value = strtoupper(trim($contentJson['evaluation']));
						if ($value === 'USELESS') {
							return 'USELESS';
						} elseif ($value === 'USEFUL') {
							return 'USEFUL';
						}
					}
				}
				
				// 如果无法从JSON提取结果，回退到文本匹配
				if (stripos($content, 'USELESS') !== false) {
					return 'USELESS';
				}
			}
		}
		
		// 默认返回USEFUL
		return 'USEFUL';
	}
	
	private function sendRequest($url, $data, $api_key) {
		$ch = curl_init($url);
		
		$headers = array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key
		);
		
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		
		$response = curl_exec($ch);
		
		if (curl_errno($ch)) {
			Minz_Log::error(_t('ext.ai_marker.error_api_request') . ': ' . curl_error($ch));
			return false;
		}
		
		curl_close($ch);
		return $response;
	}
}
