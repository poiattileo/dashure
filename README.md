# [URE] Dashure

Plugin para GLPI 12 que adiciona a aba **Início** na página inicial (Central), aberta por padrão a cada login para quem tem acesso. Dentro dela há sub-abas:

- **Anotações** (principal): logins e senhas da equipe em cartões com busca e filtro por categoria. Senha e observações ficam criptografadas com a chave do GLPI; cada anotação é liberada para os perfis marcados nela; a senha só aparece ao clicar em mostrar ou copiar (some sozinha após 30 s) e cada acesso fica no histórico da anotação.
- **Tralla:** contagem de cards do técnico, backups pendentes, pedidos aguardando decisão e cards ativos com link direto.
- **Assignat-ure:** pedidos aguardando assinatura, lotes por e-mail, papel sem upload e assinados hoje.
- **Avisos e atalhos:** mural de avisos com tipo, período e fixação, e botões de acesso rápido.

## Permissões (Administração → Perfis → aba Dashure)

| Permissão | Opções |
|---|---|
| Aba Início | sem acesso · ver · ver e gerenciar avisos e atalhos |
| Anotações | sem acesso · ver as liberadas para o perfil · ver e criar (edita as próprias) · gerenciar todas |

Na instalação só os perfis que podem alterar a configuração do GLPI recebem acesso; os demais começam sem.
