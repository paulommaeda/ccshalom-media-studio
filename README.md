# CCShalom Media Studio

Plugin WordPress para gerar campanhas de mensagens com quatro artes: convite e live (Stories 9:16), thumbnail da live e thumbnail da mensagem gravada (16:9).

Versão: **0.2.0**. Requer PHP 8.0+, WordPress 6.2+ e GD; uma conta com API e faturamento em pelo menos um provedor de imagem. A assinatura ChatGPT não concede créditos de API.

## Instalação

1. Baixe o ZIP do código em **Code > Download ZIP** no GitHub.
2. Em **Plugins > Adicionar plugin > Enviar plugin**, envie o ZIP, instale e ative.
3. Abra **CCShalom Studio > Configurações**. Selecione um provedor, configure a chave ou credenciais, selecione a logo branca OFICIAL, o símbolo da pomba para marca d'água, e (opcionalmente) uma fonte .ttf autorizada.
4. Crie uma campanha em **Nova campanha**, preencha tema, pregador, data, horários, endereço, cena e envie a foto do pastor pela Biblioteca de Mídia.
5. Clique **Gerar 4 artes**. O processamento ocorre em etapas pelo WP-Cron; atualize a página para acompanhar.

## Como funciona

O provedor selecionado gera duas imagens de **cenário sem texto, pessoas ou logos**. O plugin usa o GD localmente para compor as quatro artes, sobrepor o arquivo oficial da logo, a marca d'água, textos exatos, e a foto original do pregador na thumbnail gravada. Recomenda-se foto do pastor já recortada em **PNG transparente** para melhor resultado (não há recriação da face). Os arquivos gerados ficam na Biblioteca de Mídia do WordPress.

## Atualizações via painel

O plugin inclui verificação de atualização do branch `main` do repositório público e instalação pelo atualizador nativo de **Plugins** do WordPress. Ao publicar uma versão nova, aumente o cabeçalho `Version` no PHP principal e `CCSM_VERSION`; o WordPress detectará a nova versão. O ZIP do GitHub é renomeado automaticamente para manter a pasta do plugin.

## Segurança e limites

- Chaves salvas em opções não carregadas automaticamente pelo WordPress; não exibidas novamente. Para Google Cloud em produção, prefira a constante CCSM_VERTEX_CREDENTIALS_JSON no wp-config.php.
- Somente administradores conseguem editar dados e gerar artes.
- **Nenhum serviço é chamado sem a chave configurada e confirmação de gerar.**
- As gerações consomem créditos pagos da API.
- WP-Cron depende de visitas ao site ou de um cron real no servidor. Em hospedagens com pouco tráfego, configure o cron para chamar `wp-cron.php`.
- Primeira versão requer validação em ambiente WordPress real, especialmente desempenho do GD, fontes e tempo limite da API.

## Formatos

- Story convite: 1080 × 1920 PNG
- Story transmissão: 1080 × 1920 PNG
- Thumbnail transmissão: 1280 × 720 PNG
- Thumbnail gravada: 1280 × 720 PNG

Código mantido em https://github.com/paulommaeda/ccshalom-media-studio.


## Escolha de IA: cinco provedores

O plugin agora permite escolher **OpenAI**, **Gemini (Google AI Studio)**, **Google Cloud Vertex AI (Gemini/Imagen)**, **Stability AI (Stable Image Core)** e **Replicate (FLUX Schnell e modelos oficiais)**. A geração das quatro artes usa duas imagens de fundo do provedor escolhido, e mantém o layout, logo, foto original do pastor e textos via processamento local.

Cada provedor requer credenciais, disponibilidade e faturamento próprios. Configure em **CCShalom Studio → Configurações**. Veja as instruções e restrições de segurança em [docs/providers.md](docs/providers.md).
