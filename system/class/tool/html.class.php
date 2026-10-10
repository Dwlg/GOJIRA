<?php
/*
	Tools - HTML
		Extensão do tools com funções que ajudam na escrita de html.
*/

		class Tool_Html{
			
			
			//Escapa um valor para conteúdo ou atributo HTML. Entidades que já vêm
			//no valor são mantidas (sem escape duplo); valor simples sai igual.
			static function escapeAttr( $value ){
				if( !is_scalar( $value ) ) return '';
				return htmlspecialchars( (string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
			}
			
			
			//Escapa uma URL para href/src sem mexer no "&" que separa os parâmetros:
			//só aspas e sinais de tag são trocados, então URL simples sai igual.
			static function escapeUrl( $value ){
				if( !is_scalar( $value ) ) return '';
				return strtr( (string)$value, [ '"' => '&quot;', "'" => '&#039;', '<' => '&lt;', '>' => '&gt;' ] );
			}
			
			
			//Parâmetro de $_GET pronto para a query string de um link
			private static function queryValue( $name ){
				if( !isset( $_GET[ $name ] ) || !is_scalar( $_GET[ $name ] ) ) return '';
				return $name . '=' . urlencode( (string)$_GET[ $name ] ) . '&';
			}
			
			
			//Carrega arquivos linkados para o header da pagina
			function load( $type, $file, $refresh = true, $url = false ){
				
				switch( $type ){
					case 'js':
						echo '<script type="text/javascript" src="' . self::escapeUrl( $url ? $file : 'js/' . $file . '.js' ) . ($refresh ? '?' . @date('YmdHis') : '') . '"></script>';
						break;
					case 'css':
						echo '<link rel="stylesheet" type="text/css" href="' . self::escapeUrl( $url ? $file : 'css/' . $file . '.css' ) . ($refresh ? '?' . @date('YmdHis') : '') . '" media="all" />';
						break;
				}
				echo "\n";
				
			}
			
			
			
			
			// SEO
			function getSeo($title, $description, $keywords, $googlebot = "all, follow", $robots = "all, follow", $canonical = false){
					
				printf("<title>%s</title>\n", self::escapeAttr($title));
				printf("<meta name=\"description\" content=\"%s\">\n", self::escapeAttr(preg_replace("/\[(.*?)\]/is","",strip_tags($description))));
				printf("<meta name=\"keywords\" content=\"%s\">\n", self::escapeAttr($keywords));
				printf("<meta name=\"copyright\" content=\"Agência Freela\">\n");
				printf("<meta name=\"googlebot\" content=\"%s\">\n", self::escapeAttr($googlebot));
				printf("<meta name=\"robots\" content=\"%s\">\n", self::escapeAttr($robots));
						
				if($canonical){
				
					printf("<meta name=\"canonical\" content=\"%s\">\n", self::escapeUrl($canonical));
				}
			
			}
			
			
			
			
			//Escreve o menu de paginação do registros
		   function pagination( $qtde, $pagQtde, $url, $pag = 0, $ajax = true ){
				
				$ret   = '';
				
		      $nItem = ceil( $qtde / $pagQtde );
				if( substr( $url, -1, 1 ) != '/' ) $url .= '/';
            
				if( $ajax ){
					
					if( strpos( $url, '/pag/' ) !== false ){
						$url = substr( $url, 0, strpos( $url, '/pag/' ) ) .
									substr( $url, strpos( $url, '/', strpos( $url, '/pag/' ) + 5 ) );
					}
					$url = substr( $url, 0, strlen( $url ) - 1 );
					
					$link = $url . '/pag/';
					
				} else {
					
			      //Valores de $_GET vão com urlencode (valor que não é texto é ignorado)
			      $link  = $url . '?' . self::queryValue('id') . self::queryValue('view') . self::queryValue('type') .
			                            self::queryValue('sort') . self::queryValue('inv') . 'pag=';
				
				}
		
		      if( $nItem > 1 ){
		         $link = self::escapeUrl( $link );
		         $ret .= '<div class="pagination"><ul>';
		         for( $c = 1; $c <= $nItem; $c++ ){
		            if( $pag == $c ){
		               $ret .= '<li><strong>' . $c . '</strong></li>';
		            } else {
		               $ret .= '<li><span><a href="' . $link . $c . '">' . $c . '</a></span></li>';
		            }
		         }
		         $ret .= '</ul></div>';
		      }
            
				return $ret;
		   }
			
			
			
			//Escreve um javascript na pagina
		   function script( $a ){
		      return '<script type="text/javascript">' .  $a . '</script>';
		   }
			
			
			//Escreve um campo de cabeçario de uma tabela
			function columnHeader( $url, $sort, $inv, $field, $title){
				
            $icon_up   = ICON_SORT_ASC;
            $icon_down = ICON_SORT_DESC;
            
				if( empty( $sort ) ){
					echo '<th>' . $title . '</th>';
				} else {
				   echo '<th><a href="' . self::escapeUrl( $url ) . 'sort/' . self::escapeAttr( $field ) . ($sort == $field && $inv == false ? '/inv/1/' : '/') . '">' .
                    ($sort == $field ? ($inv ? $icon_up  : $icon_down) : '') . $title . '</a></th>';
				}
				
			}
			
			
		}
