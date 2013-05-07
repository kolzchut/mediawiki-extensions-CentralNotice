<?php

class BannerRenderer {
	/**
	 * @var IContextSource $context
	 */
	protected $context;

	/**
	 * @var Banner $banner
	 */
	protected $banner;

	/**
	 * Campaign in which context the rendering is taking place.  Empty during preview.
	 *
	 * @var string $campaignName
	 */
	protected $campaignName = "";

	protected $mixinController = null;

	function __construct( IContextSource $context, Banner $banner, $campaignName = null, AllocationContext $allocContext = null ) {
		$this->context = $context;

		$this->banner = $banner;
		$this->campaignName = $campaignName;

		if ( $allocContext === null ) {
			/**
			 * This should only be used when banners are previewed in management forms.
			 * TODO: set realistic context in the admin ui, drawn from the campaign
			 * configuration and current translation settings.
			 */
			$this->allocContext = new AllocationContext( 'XX', 'en', 'wikipedia', true, 'desktop', 0 );
		} else {
			$this->allocContext = $allocContext;
		}

		$this->mixinController = new MixinController( $this->context, $this->banner->getMixins(), $allocContext );

		//FIXME: it should make sense to do this:
		// $this->mixinController->registerMagicWord( 'campaign', array( $this, 'getCampaign' ) );
		// $this->mixinController->registerMagicWord( 'banner', array( $this, 'getBanner' ) );
	}

	function linkTo() {
		return Linker::link(
			SpecialPage::getTitleFor( 'CentralNoticeBanners', "edit/{$this->banner->getName()}" ),
			htmlspecialchars( $this->banner->getName() ),
			array( 'class' => 'cn-banner-title' )
		);
	}

	/**
	 * Render the banner as an html fieldset
	 * This actually renders a fieldset with an iframe inside of it
	 */
	function previewFieldSet() {
		$previewUrl = SpecialPage::getTitleFor( 'BannerPreview' )->getLocalURL(
			'',
			array(
				 'banner' => $this->banner->getName(),
				 'uselang' => $this->allocContext->getLanguage(),
				 'force' => '1'
			)
		);
		$preview = Xml::tags(
			'iframe',
			array(
				 'src' => $previewUrl,
				 'width' => "100%",
				 'seamless' => 'seamless',
				 'frameborder' => 0,
			),
			wfMessage( 'centralnotice-noiframe' )
		);

		$lang = $this->context->getLanguage()->getCode();
		$label = $this->context->msg( 'centralnotice-preview', $lang )->text();

		return Xml::fieldset(
			$label,
			$preview,
			array(
				 'class' => 'cn-bannerpreview',
				 'id' => Sanitizer::escapeId( "cn-banner-preview-{$this->banner->getName()}" ),
			)
		);
	}

	/**
	 * Get the body of the banner, with all transformations applied.
	 *
	 * FIXME: "->inLanguage( $context->getLanguage() )" is necessary due to a bug in DerivativeContext
	 */
	function toHtml() {
		$bannerHtml = $this->context->msg( $this->banner->getDbKey() )->inLanguage( $this->context->getLanguage() )->text();
		$bannerHtml .= $this->getResourceLoaderHtml();

		return $this->substituteMagicWords( $bannerHtml );
	}

	function getPreloadJs() {
		$snippets = $this->mixinController->getPreloadJsSnippets();
		if ( $snippets ) {
			$bundled = array();
			foreach ( $snippets as $mixin => $code ) {
				if ( !$this->context->getRequest()->getFuzzyBool( 'debug' ) ) {
					$code = JavaScriptMinifier::minify( $code );
				}

				$bundled[] = "/* {$mixin}: */{$code}";
			}
			$js = implode( " && ", $bundled );
			return $this->substituteMagicWords( $js );
		}
		return "";
	}

	function getResourceLoaderHtml() {
		$modules = $this->mixinController->getResourceLoaderModules();
		if ( $modules ) {
			$html = "<!-- " . implode( ", ", array_keys( $modules ) ) . " -->";
			$html .= Html::inlineScript(
				ResourceLoader::makeLoaderConditionalScript(
					Xml::encodeJsCall( 'mw.loader.load', array_values( $modules ) )
				)
			);
			return $html;
		}
		return "";
	}

	function substituteMagicWords( $contents ) {
		return preg_replace_callback(
			'/{{{([^}:]+)(?:[:]([^}]*))?}}}/',
			array( $this, 'renderMagicWord' ),
			$contents
		);
	}

	function getMagicWords() {
		$words = array( 'banner', 'campaign' );
		$words = array_merge( $words, $this->mixinController->getMagicWords() );
		return $words;
	}

	protected function renderMagicWord( $re_matches ) {
		$field = $re_matches[1];
		if ( $field === 'banner' ) {
			return $this->banner->getName();
		} elseif ( $field === 'campaign' ) {
			return $this->campaignName;
		}
		$params = array();
		if ( isset( $re_matches[2] ) ) {
			$params = explode( "|", $re_matches[2] );
		}

		$value = $this->mixinController->renderMagicWord( $field, $params );
		if ( $value !== null ) {
			return $value;
		}

		$bannerMessage = $this->banner->getMessageField( $field );
		return $bannerMessage->toHtml( $this->context );
	}
}