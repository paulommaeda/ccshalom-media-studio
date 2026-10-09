# Provedores de geração de imagem — CCShalom Media Studio 0.2.0

O cenário é gerado em uma API. O plugin compõe localmente a logo oficial, texto em português, marca-d'água e foto original do pastor. Assim, a identidade visual e o rosto não são gerados por IA.

## Provedores e modelos

| Provedor | Modelo padrão | Autenticação |
| --- | --- | --- |
| OpenAI | `gpt-image-1.5` | OpenAI API Key |
| Gemini Google AI Studio | `gemini-2.5-flash-image` | Gemini API Key |
| Google Cloud Vertex AI | `gemini-2.5-flash-image` (ou `imagen-4.0-generate-001`) | OAuth2 via service account |
| Stability AI | Stable Image Core | Stability API Key |
| Replicate | `black-forest-labs/flux-schnell` | Replicate API token |

## Google AI Studio

1. Crie uma API Key em https://aistudio.google.com/apikey.
2. No plugin, selecione **Google AI Studio — Gemini**.
3. Cole a chave e escolha `gemini-2.5-flash-image` ou outro modelo que gere imagens.
4. Salve. O modelo será solicitado com as proporções 9:16 e 16:9.

## Google Cloud Vertex AI (Gemini e Imagen)

1. Em https://console.cloud.google.com habilite uma conta de faturamento para o projeto.
2. Habilite a Vertex AI API no projeto e crie uma conta de serviço com apenas as permissões necessárias.
3. Configure o Project ID e localização (como `us-central1`).
4. Modelo: `gemini-2.5-flash-image` para Gemini, ou `imagen-4.0-generate-001` para Imagen.
5. Recomendado: guarde as credenciais JSON da conta de serviço **fora do GitHub** e insira no ambiente do servidor. No `wp-config.php`, inclua:

    define('CCSM_VERTEX_CREDENTIALS_JSON', getenv('CCSM_VERTEX_CREDENTIALS_JSON'));

Alternativamente, cole o JSON da conta de serviço no campo próprio da configuração. O plugin não mostra o JSON novamente; a opção de exclusão remove o valor armazenado no WordPress. Faça rotação e restringa o acesso às credenciais conforme as políticas da sua organização.

O plugin usa OpenSSL para assinar uma solicitação JWT e obter um token OAuth2 temporário do Google Cloud. A geração Gemini chama `:generateContent` e Imagen chama `:predict`. A saída é recebida em base64.

## Stability AI

Selecione **Stability AI** e cadastre a chave. O plugin chama Stable Image Core com dados multipart e solicita PNG.

## Replicate

Selecione **Replicate** e cadastre seu token. O padrão é `black-forest-labs/flux-schnell`. Outros modelos precisam ser **modelos oficiais** compatíveis com os parâmetros `prompt`, `aspect_ratio`, `output_format` e `num_outputs`; cada modelo pode exigir entradas diferentes. A API é consultada até a predição ficar pronta e a imagem é baixada apenas de URLs HTTPS da rede `replicate.delivery`.

## Segurança, cobrança e limites

- Cada campanha usa duas gerações de cenário (vertical e horizontal). Os quatro PNGs finais são produzidos no WordPress.
- As chaves nunca devem ser fornecidas neste chat ou colocadas em arquivos públicos do GitHub.
- A assinatura ChatGPT não oferece acesso gratuito às APIs. Cada provedor cobra conforme seus próprios preços e planos.
- Nem todos os modelos estão disponíveis em todas as regiões ou contas; verifique faturamento, cotas e permissões.
- Se o modelo selecionado não gerar imagens, o plugin mostrará um erro da API.
- A qualidade de composição depende de PHP GD e de uma fonte tipográfica TTF instalada/autorizada.
- Fotos originais de pregadores e o logo **não são enviados ao provedor de imagem** nesta versão.

## Documentação oficial

- OpenAI: https://platform.openai.com/docs/guides/image-generation
- Gemini: https://ai.google.dev/gemini-api/docs/image-generation
- Google Cloud Vertex AI: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/start/quickstart
- Stability AI: https://platform.stability.ai/docs/api-reference
- Replicate: https://replicate.com/docs/reference/http/
